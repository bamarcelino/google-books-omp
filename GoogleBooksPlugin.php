<?php

declare(strict_types=1);

/**
 * @file GoogleBooksPlugin.php
 *
 * Google Books Integration for Open Monograph Press 3.5.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\DashboardHandler;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryManager;
use APP\plugins\generic\googleBooks\classes\Feed\FeedHandler;
use APP\plugins\generic\googleBooks\classes\Jobs\SubmissionDiscoveryJob;
use APP\plugins\generic\googleBooks\classes\Jobs\SubmissionSyncJob;
use APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration;
use APP\plugins\generic\googleBooks\classes\Migration\PluginSettingsMigrator;
use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
use APP\plugins\generic\googleBooks\classes\Security\SecretStore;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Event;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\observers\events\MetadataChanged;
use PKP\observers\events\PublicationPublished;
use PKP\observers\events\PublicationUnpublished;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\PKPSubmission;
use PKP\submissionFile\SubmissionFile;
use Throwable;

final class GoogleBooksPlugin extends GenericPlugin
{
    public const PLUGIN_NAME = PluginSettingsMigrator::CANONICAL_PLUGIN_NAME;
    public const PRODUCT_NAME = 'googleBooks';
    public const DASHBOARD_PAGE = 'googlebooks';
    public const FEED_PAGE = 'googlebooksfeed';
    public const VERSION = '0.1.2.15';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        // Repair settings written by 0.1.0.0/0.1.0.1 before checking whether
        // the plugin is enabled. This plugin remains deliberately non-lazy so the
        // repair path can still run on installations affected by the early
        // plugin-identity mismatch.
        $migrationSucceeded = PluginSettingsMigrator::migrate();
        $enabled = $this->getEnabled($mainContextId);
        if (!$enabled && !$migrationSucceeded) {
            $contextId = $mainContextId ?? Application::get()->getRequest()->getContext()?->getId();
            $enabled = PluginSettingsMigrator::legacyEnabled($contextId === null ? null : (int) $contextId);
        }

        if ($enabled) {
            $this->addLocaleData();
            Hook::add('LoadHandler', $this->handlePage(...));
            Hook::add('TemplateManager::display', $this->addPublicGoogleBooksStyles(...));
            // OMP calls Templates::Catalog::Book::Details after publication-format
            // identifiers/DOIs. The bundled Citation Style Language plugin uses
            // the default SEQUENCE_NORMAL on the same hook. Register at CORE so
            // Google Books is rendered immediately after the identifier block
            // and before the citation widget, without overriding core templates.
            Hook::add('Templates::Catalog::Book::Details', $this->addPublicGoogleBooksIdentifier(...), Hook::SEQUENCE_CORE);
            Hook::add('SubmissionFile::add', $this->submissionFileChanged(...));
            Hook::add('SubmissionFile::edit', $this->submissionFileChanged(...));
            Hook::add('SubmissionFile::delete', $this->submissionFileChanged(...));
            Event::listen(PublicationPublished::class, $this->publicationPublished(...));
            Event::listen(PublicationUnpublished::class, $this->publicationUnpublished(...));
            Event::listen(MetadataChanged::class, $this->metadataChanged(...));
        }
        return $success;
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.googleBooks.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.googleBooks.description');
    }

    public function getInstallMigration()
    {
        return new GoogleBooksSchemaMigration();
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $context = $request->getContext();
        if (!$context) {
            return $actions;
        }

        // The plugins list is rendered through OMP's component router. Calling
        // $request->url() from that route preserves the component placeholder
        // (for example, $$$call$$$/goog/fetch-grid) and produces a broken link.
        // Build an explicit page-route URL through the dispatcher instead.
        $dashboardUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            self::DASHBOARD_PAGE
        );

        array_unshift($actions, new LinkAction(
            'googleBooksDashboard',
            new RedirectAction($dashboardUrl),
            __('plugins.generic.googleBooks.dashboard'),
            null
        ));

        return $actions;
    }

    public function handlePage(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        $normalizedPage = strtolower((string) $page);
        if ($normalizedPage === strtolower(self::DASHBOARD_PAGE)) {
            $page = self::DASHBOARD_PAGE;
            $handler = new DashboardHandler($this);
            return true;
        }
        if ($normalizedPage === strtolower(self::FEED_PAGE)) {
            $page = self::FEED_PAGE;
            $handler = new FeedHandler($this);
            return true;
        }
        return false;
    }

    public function publicationPublished(PublicationPublished $event): void
    {
        $contextId = (int) $event->context->getId();
        $submissionId = (int) $event->submission->getId();

        // Public Google Books discovery is independent from the publisher
        // feed. A press can therefore reconcile its existing catalogue first
        // and only configure Automated Content Fetching later.
        if ($this->canAutoDiscover($contextId)) {
            SubmissionDiscoveryJob::dispatchAfterResponse($contextId, $submissionId);
        }
        if ($this->canAutoSync($contextId)) {
            SubmissionSyncJob::dispatchAfterResponse($contextId, $submissionId, false);
        }
    }

    public function publicationUnpublished(PublicationUnpublished $event): void
    {
        $contextId = (int) $event->context->getId();
        $submissionId = (int) $event->submission->getId();
        $retired = (new GoogleBooksStateRepository())->retireSubmission($contextId, $submissionId);
        if ($retired > 0) {
            $this->bumpFeedRevision($contextId);
        }
        if ((int) $this->getSetting($contextId, 'validationSubmissionId') === $submissionId) {
            $this->updateSetting($contextId, 'validationSubmissionId', 0, 'int');
        }
    }

    public function metadataChanged(MetadataChanged $event): void
    {
        $submission = $event->submission;
        $contextId = (int) $submission->getData('contextId');
        if ((int) $submission->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            return;
        }

        $submissionId = (int) $submission->getId();
        if ($this->canAutoDiscover($contextId)) {
            SubmissionDiscoveryJob::dispatchAfterResponse($contextId, $submissionId);
        }
        if ($this->canAutoSync($contextId)) {
            SubmissionSyncJob::dispatchAfterResponse($contextId, $submissionId, false);
        }
    }

    /**
     * Queue a refresh when a public production proof is added, replaced,
     * edited or removed from an already published monograph. OMP's file
     * repository exposes the new file as the first argument for add/edit and
     * the removed file as the first argument for delete.
     */
    public function submissionFileChanged(string $hookName, array $args): bool
    {
        $file = $args[0] ?? null;
        if (!$file instanceof SubmissionFile) {
            return false;
        }
        if (
            (int) $file->getData('fileStage') !== SubmissionFile::SUBMISSION_FILE_PROOF ||
            (int) $file->getData('assocType') !== Application::ASSOC_TYPE_PUBLICATION_FORMAT
        ) {
            return false;
        }

        $submissionId = (int) $file->getData('submissionId');
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return false;
        }
        $contextId = (int) $submission->getData('contextId');
        if (!$this->canAutoSync($contextId) || (int) $submission->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            return false;
        }

        // SubmissionFile::edit is emitted before OMP persists the updated
        // file record. Defer dispatch until the HTTP response is complete so
        // synchronous queue installations also map the committed revision,
        // price, visibility and association state instead of stale values.
        SubmissionSyncJob::dispatchAfterResponse($contextId, $submissionId, false);
        return false;
    }

    public function addPublicGoogleBooksIdentifier(string $hookName, array $params): bool
    {
        $templateMgr = $params[1];
        $output = &$params[2];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->boolSetting((int) $context->getId(), 'showPublicLink', true)) {
            return false;
        }

        $monograph = $templateMgr->getTemplateVars('monograph');
        $publication = $templateMgr->getTemplateVars('publication');
        if (!$monograph || !$publication || (int) $publication->getId() !== (int) $monograph->getCurrentPublication()->getId()) {
            return false;
        }

        $records = [];
        foreach ((new GoogleBooksStateRepository())->getBySubmission((int) $context->getId(), (int) $monograph->getId()) as $record) {
            if (
                (int) $record->publication_id !== (int) $publication->getId() ||
                (string) ($record->sync_status ?? '') === 'retired' ||
                (string) ($record->discovery_status ?? '') === 'multiple_matches' ||
                !$record->google_volume_id
            ) {
                continue;
            }
            $url = $this->publicGoogleBooksUrl($record);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            $records[] = [
                'booksUrl' => $url,
                'playUrl' => $this->publicGooglePlayUrl($record),
            ];
        }
        if ($records === []) {
            return false;
        }
        $templateMgr->assign('googleBooksPublicRecords', $records);
        $output .= $templateMgr->fetch($this->getTemplateResource('publicIdentifier.tpl'));
        return false;
    }

    public function addPublicGoogleBooksStyles(string $hookName, array $args): bool
    {
        $templateMgr = $args[0] ?? null;
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$templateMgr || !$context || !$this->boolSetting((int) $context->getId(), 'showPublicLink', true)) {
            return false;
        }

        $assetBase = rtrim($request->getBaseUrl(), '/') . '/' . trim($this->getPluginPath(), '/');
        $templateMgr->addStyleSheet(
            'googleBooksPublic',
            $assetBase . '/styles/public.css?v=' . rawurlencode(self::VERSION),
            ['contexts' => ['frontend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE],
        );
        return false;
    }

    public function boolSetting(int $contextId, string $name, bool $default): bool
    {
        $value = $this->getSetting($contextId, $name);
        return $value === null || $value === '' ? $default : (bool) $value;
    }


    /**
     * Return whether Google Books API discovery has a configured key without
     * decrypting or rendering the secret.
     */
    public function hasGoogleApiKey(int $contextId): bool
    {
        return trim((string) $this->getSetting($contextId, 'googleApiKeyEncrypted')) !== ''
            || trim((string) $this->getSetting($contextId, 'googleApiKey')) !== '';
    }

    /**
     * Recover the API key for outbound Google Books API requests.
     *
     * 0.1.2.2 stores new values under googleApiKeyEncrypted. If an installation
     * still has the pre-0.1.2.2 plaintext setting, migrate it opportunistically
     * after a successful read. A missing/broken app_key does not silently erase
     * the legacy value, so an upgrade cannot strand discovery before the site
     * administrator repairs the OMP application key.
     */
    public function getGoogleApiKey(int $contextId): string
    {
        $encrypted = trim((string) $this->getSetting($contextId, 'googleApiKeyEncrypted'));
        if ($encrypted !== '') {
            return SecretStore::decryptApiKey($encrypted);
        }

        $legacy = trim((string) $this->getSetting($contextId, 'googleApiKey'));
        if ($legacy === '') {
            return '';
        }

        try {
            $this->setGoogleApiKey($contextId, $legacy);
        } catch (Throwable $e) {
            error_log('Google Books API-key encryption migration deferred: ' . $e->getMessage());
        }
        return $legacy;
    }

    public function setGoogleApiKey(int $contextId, string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return;
        }
        $encrypted = SecretStore::encryptApiKey($apiKey);
        $this->updateSetting($contextId, 'googleApiKeyEncrypted', $encrypted, 'string');
        // Remove the legacy plaintext setting only after encryption succeeded.
        $this->updateSetting($contextId, 'googleApiKey', '', 'string');
    }

    public function clearGoogleApiKey(int $contextId): void
    {
        $this->updateSetting($contextId, 'googleApiKeyEncrypted', '', 'string');
        $this->updateSetting($contextId, 'googleApiKey', '', 'string');
    }

    public function getFeedRevision(int $contextId): int
    {
        $revision = (int) $this->getSetting($contextId, 'feedRevision');
        if ($revision <= 0) {
            $revision = time();
            $this->updateSetting($contextId, 'feedRevision', $revision, 'int');
        }
        return $revision;
    }

    public function bumpFeedRevision(int $contextId): int
    {
        $current = (int) $this->getSetting($contextId, 'feedRevision');
        $revision = max(time(), $current + 1);
        $this->updateSetting($contextId, 'feedRevision', $revision, 'int');
        return $revision;
    }

    /** @return array<string,string> */
    public function getImprintCollectionMap(int $contextId): array
    {
        $raw = (string) $this->getSetting($contextId, 'imprintCollectionMap');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $imprint => $code) {
            $code = self::normalizeCollectionCode((string) $code);
            if ((string) $imprint !== '' && self::isValidCollectionCode($code)) {
                $map[(string) $imprint] = $code;
            }
        }
        return $map;
    }

    /** @return string[] */
    public function getCollectionCodes(int $contextId): array
    {
        $codes = [];
        $default = self::normalizeCollectionCode((string) $this->getSetting($contextId, 'collectionCode'));
        if (self::isValidCollectionCode($default)) {
            $codes[] = $default;
        }
        foreach ($this->getImprintCollectionMap($contextId) as $code) {
            $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }

    public function getCollectionCodeForBook(int $contextId, BookMetadata $book): string
    {
        if ($book->imprint) {
            foreach ($this->getImprintCollectionMap($contextId) as $imprint => $code) {
                if ($this->lower(trim($imprint)) === $this->lower(trim($book->imprint))) {
                    return $code;
                }
            }
        }
        return self::normalizeCollectionCode((string) $this->getSetting($contextId, 'collectionCode'));
    }

    /**
     * Normalize a Google Play Books collection code copied from Partner Center.
     *
     * Partner Center documents collection codes as seven alphanumeric
     * characters. Copy/paste can occasionally include Unicode spacing or
     * zero-width characters, so remove spacing only and preserve any other
     * invalid character for the validator to reject explicitly.
     */
    public static function normalizeCollectionCode(string $value): string
    {
        $value = trim($value);
        $normalized = preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', $value);
        return strtoupper($normalized ?? $value);
    }

    public static function isValidCollectionCode(string $value): bool
    {
        return preg_match('/^[A-Z0-9]{7}$/', self::normalizeCollectionCode($value)) === 1;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function publicGoogleBooksUrl(object $record): string
    {
        // volumeInfo.infoLink is not guaranteed to stay on books.google.com;
        // for sale-enabled e-books Google frequently returns a Play Store URL.
        // The Volume ID is the stable identity shared by both products, so use
        // it to keep the Books action distinct from saleInfo.buyLink/Play.
        $volumeId = trim((string) ($record->google_volume_id ?? ''));
        if ($volumeId !== '') {
            return 'https://books.google.com/books?id=' . rawurlencode($volumeId);
        }

        foreach ([$record->google_info_link ?? null, $record->google_preview_link ?? null] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return trim((string) ($record->google_self_link ?? ''));
    }

    /**
     * Return Google's own acquisition URL only when the Books API confirms
     * that the matched volume is an e-book offered in the relevant storefront.
     */
    private function publicGooglePlayUrl(object $record): string
    {
        if (!(bool) ($record->google_is_ebook ?? false)) {
            return '';
        }

        $saleability = strtoupper(trim((string) ($record->google_saleability ?? '')));
        if (!in_array($saleability, ['FREE', 'FOR_SALE', 'FOR_PREORDER', 'FOR_RENTAL_ONLY', 'FOR_SALE_AND_RENTAL'], true)) {
            return '';
        }

        $url = trim((string) ($record->google_buy_link ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return $url;
    }

    private function canAutoDiscover(int $contextId): bool
    {
        return $this->boolSetting($contextId, 'autoDiscovery', true)
            && $this->hasGoogleApiKey($contextId);
    }

    private function canAutoSync(int $contextId): bool
    {
        if (!$this->boolSetting($contextId, 'autoSync', false)) {
            return false;
        }
        try {
            return (bool) (new DeliveryManager($this))->readiness($contextId)['ready'];
        } catch (\Throwable) {
            return false;
        }
    }
}
