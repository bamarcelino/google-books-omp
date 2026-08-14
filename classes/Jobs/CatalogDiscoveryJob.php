<?php

declare(strict_types=1);

/**
 * Resumable, bounded Google Books API discovery for large OMP catalogues.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
use APP\plugins\generic\googleBooks\classes\Sync\GoogleBooksSyncService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use PKP\plugins\PluginRegistry;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class CatalogDiscoveryJob extends GoogleBooksJob
{
    private const BATCH_SIZE = 10;

    public int $timeout = 600;
    public $tries = 3;

    /** @param int[] $submissionIds */
    public function __construct(
        public int $contextId,
        public ?int $userId = null,
        public array $submissionIds = [],
        public int $offset = 0,
        public ?int $runId = null,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $plugin = $this->plugin();
        if (!$plugin->getEnabled($this->contextId)) {
            return;
        }
        if (!$plugin->hasGoogleApiKey($this->contextId)) {
            return;
        }
        $context = Application::getContextDAO()->getById($this->contextId);
        if (!$context) {
            throw new RuntimeException('OMP press context not found.');
        }

        $repository = new GoogleBooksStateRepository();
        $submissionIds = $this->submissionIds;
        $runId = $this->runId;
        if ($runId === null) {
            $submissionIds = [];
            foreach (Repo::submission()->getCollector()
                ->filterByContextIds([$this->contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany() as $submission) {
                $submissionIds[] = (int) $submission->getId();
            }
            $runId = $repository->createRun($this->contextId, 'discovery', $this->userId);
            if ($submissionIds === []) {
                $repository->finishRun($runId, 'completed', $this->emptyCounters(), null);
                return;
            }
        }

        $batchIds = array_slice($submissionIds, $this->offset, self::BATCH_SIZE);
        $counters = $this->emptyCounters();
        $errors = [];
        $service = new GoogleBooksSyncService($plugin);

        foreach ($batchIds as $submissionId) {
            $counters['scanned']++;
            try {
                $submission = Repo::submission()->get((int) $submissionId);
                if (!$submission || (int) $submission->getData('contextId') !== $this->contextId || (int) $submission->getData('status') !== Submission::STATUS_PUBLISHED) {
                    $counters['skipped']++;
                    continue;
                }
                $result = $service->discoverSubmission($submission, $context);
                foreach (['linked', 'notFound', 'retryable', 'failed', 'skipped'] as $key) {
                    $counters[$key] += $result[$key];
                }
                foreach ($result['errors'] as $error) {
                    $errors[] = 'Submission ' . $submissionId . ': ' . $error;
                }
            } catch (Throwable $e) {
                $counters['failed']++;
                $errors[] = 'Submission ' . $submissionId . ': ' . $e->getMessage();
            }

            // Avoid sending a large legacy catalogue to the public Books API
            // as a burst. This pause is deliberately small and applies only to
            // background discovery jobs.
            usleep(250000);
        }

        $nextOffset = $this->offset + count($batchIds);
        $final = $nextOffset >= count($submissionIds);
        $repository->appendRunBatch($runId, $counters, $errors, $final);

        if (!$final) {
            self::dispatch($this->contextId, $this->userId, $submissionIds, $nextOffset, $runId)
                ->onConnection((string) ($this->connection ?: 'database'))
                ->delay((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 seconds'));
        }
    }

    /** @return array{scanned:int,linked:int,notFound:int,retryable:int,updated:int,unchanged:int,retired:int,failed:int,skipped:int,feedIneligible:int} */
    private function emptyCounters(): array
    {
        return [
            'scanned' => 0,
            'linked' => 0,
            'notFound' => 0,
            'retryable' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'retired' => 0,
            'failed' => 0,
            'skipped' => 0,
            'feedIneligible' => 0,
        ];
    }

    private function plugin(): GoogleBooksPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', GoogleBooksPlugin::PLUGIN_NAME);
        if (!$plugin) {
            $plugin = PluginRegistry::loadPlugin('generic', GoogleBooksPlugin::PRODUCT_NAME, $this->contextId);
        }
        if (!$plugin instanceof GoogleBooksPlugin) {
            throw new RuntimeException('Google Books plugin is not available.');
        }
        return $plugin;
    }
}
