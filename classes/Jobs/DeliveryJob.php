<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Jobs;

use APP\core\Application;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryManager;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use PKP\plugins\PluginRegistry;
use RuntimeException;

final class DeliveryJob extends GoogleBooksJob
{
    public int $timeout = 3600;
    public $tries = 2;

    public function __construct(
        public int $contextId,
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
        if (DeliveryConfig::mode($plugin, $this->contextId) === DeliveryConfig::HTTP_PULL) {
            return;
        }
        $context = Application::getContextDAO()->getById($this->contextId);
        if (!$context) {
            throw new RuntimeException('OMP press context not found.');
        }
        (new DeliveryManager($plugin))->deliver($context, $this->force);
    }
}
