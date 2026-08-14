<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Sync\GoogleBooksSyncService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use DateTimeImmutable;
use DateTimeZone;
use PKP\plugins\PluginRegistry;
use RuntimeException;

/**
 * Bounded post-crawl discovery retry for newly exposed books.
 *
 * The delays are a plugin policy, not a Google processing-time guarantee.
 */
final class BookVerificationJob extends GoogleBooksJob
{
    private const CHECKPOINTS_HOURS = [6, 24, 72, 168];

    public int $timeout = 300;

    public function __construct(
        public int $contextId,
        public int $submissionId,
        public int $attemptNumber = 1,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $plugin = PluginRegistry::getPlugin('generic', GoogleBooksPlugin::PLUGIN_NAME);
        if (!$plugin) {
            $plugin = PluginRegistry::loadPlugin('generic', GoogleBooksPlugin::PRODUCT_NAME, $this->contextId);
        }
        if (!$plugin instanceof GoogleBooksPlugin) {
            throw new RuntimeException('Google Books plugin is not available.');
        }
        if (!$plugin->getEnabled($this->contextId)) {
            return;
        }

        $context = Application::getContextDAO()->getById($this->contextId);
        $submission = Repo::submission()->get($this->submissionId);
        if (!$context || !$submission || (int) $submission->getData('contextId') !== $this->contextId) {
            throw new RuntimeException('OMP press or submission not found.');
        }
        if ((int) $submission->getData('status') !== Submission::STATUS_PUBLISHED) {
            return;
        }

        $result = (new GoogleBooksSyncService($plugin))->verifySubmission($submission, $context);
        // A monograph can expose more than one ISBN product. Finding one of
        // them must not stop retries while another product remains absent.
        if ($result['retryable'] === 0 || !$plugin->boolSetting($this->contextId, 'autoVerifyGoogle', true)) {
            return;
        }

        $nextAttempt = $this->attemptNumber + 1;
        if ($nextAttempt > count(self::CHECKPOINTS_HOURS)) {
            return;
        }

        $runAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::delayHoursForAttempt($nextAttempt) . ' hours');
        self::dispatch($this->contextId, $this->submissionId, $nextAttempt)->delay($runAt);
    }

    /**
     * Delay from the previous checkpoint to this attempt.
     */
    public static function delayHoursForAttempt(int $attemptNumber): int
    {
        if ($attemptNumber < 1 || $attemptNumber > count(self::CHECKPOINTS_HOURS)) {
            throw new \InvalidArgumentException('Invalid Google Books verification attempt.');
        }
        $index = $attemptNumber - 1;
        return $index === 0
            ? self::CHECKPOINTS_HOURS[0]
            : self::CHECKPOINTS_HOURS[$index] - self::CHECKPOINTS_HOURS[$index - 1];
    }
}
