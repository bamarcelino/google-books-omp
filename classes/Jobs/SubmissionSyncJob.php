<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\classes\Sync\GoogleBooksSyncService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use DateTimeImmutable;
use DateTimeZone;
use PKP\plugins\PluginRegistry;
use RuntimeException;

final class SubmissionSyncJob extends GoogleBooksJob
{
    public int $timeout = 300;
    public $tries = 2;

    public function __construct(
        public int $contextId,
        public int $submissionId,
        public bool $force = false,
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
        if (!$plugin->getEnabled($this->contextId) || !$plugin->boolSetting($this->contextId, 'feedEnabled', false)) {
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

        $result = (new GoogleBooksSyncService($plugin))->syncSubmission($submission, $context, $this->force);
        if ($this->force || $result['feedChanged']) {
            $plugin->bumpFeedRevision($this->contextId);
        }

        if (($this->force || ($result['feedChanged'] ?? false)) && DeliveryConfig::mode($plugin, $this->contextId) !== DeliveryConfig::HTTP_PULL) {
            DeliveryJob::dispatch($this->contextId, $this->force);
        }

        if (
            ($this->force || $result['updated'] > 0) &&
            $plugin->boolSetting($this->contextId, 'autoVerifyGoogle', true) &&
            $plugin->hasGoogleApiKey($this->contextId)
        ) {
            $runAt = (new DateTimeImmutable('now', new DateTimeZone('UTC'))
                )->modify('+' . BookVerificationJob::delayHoursForAttempt(1) . ' hours');
            BookVerificationJob::dispatch($this->contextId, $this->submissionId, 1)->delay($runAt);
        }
    }
}
