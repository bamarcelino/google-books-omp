<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\classes\Sync\GoogleBooksSyncService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use DateTimeImmutable;
use DateTimeZone;
use PKP\plugins\PluginRegistry;
use RuntimeException;

final class CatalogSyncJob extends GoogleBooksJob
{
    public int $timeout = 1800;
    public $tries = 2;

    public function __construct(
        public int $contextId,
        public bool $force = false,
        public ?int $userId = null,
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
        if (!$context) {
            throw new RuntimeException('OMP press context not found.');
        }

        $result = (new GoogleBooksSyncService($plugin))->syncCatalog($context, $this->force, $this->userId);

        if (($this->force || ($result['updated'] ?? 0) > 0) && DeliveryConfig::mode($plugin, $this->contextId) !== DeliveryConfig::HTTP_PULL) {
            DeliveryJob::dispatch($this->contextId, $this->force);
        }

        // Feed synchronization and API discovery are separate operations. After
        // the crawler has had time to ingest newly exposed files, a bounded API
        // discovery pass may link the resulting public Google Volume IDs.
        if (
            ($this->force || $result['updated'] > 0) &&
            $plugin->boolSetting($this->contextId, 'autoVerifyGoogle', true) &&
            trim((string) $plugin->getSetting($this->contextId, 'googleApiKey')) !== ''
        ) {
            $runAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 hours');
            CatalogDiscoveryJob::dispatch($this->contextId, $this->userId)->delay($runAt);
        }
    }
}
