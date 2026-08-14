<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Sync\GoogleBooksSyncService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use PKP\plugins\PluginRegistry;
use RuntimeException;

final class SubmissionDiscoveryJob extends GoogleBooksJob
{
    public int $timeout = 180;
    public $tries = 3;

    public function __construct(
        public int $contextId,
        public int $submissionId,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $plugin = $this->plugin();
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
        if (!$plugin->hasGoogleApiKey($this->contextId)) {
            return;
        }

        (new GoogleBooksSyncService($plugin))->discoverSubmission($submission, $context);
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
