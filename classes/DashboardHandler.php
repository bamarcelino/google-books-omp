<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryManager;
use APP\plugins\generic\googleBooks\classes\Delivery\SftpEndpoint;
use APP\plugins\generic\googleBooks\classes\Delivery\TransportCapabilities;
use APP\plugins\generic\googleBooks\classes\Feed\FeedManifestService;
use APP\plugins\generic\googleBooks\classes\Jobs\CatalogDiscoveryJob;
use APP\plugins\generic\googleBooks\classes\Jobs\CatalogSyncJob;
use APP\plugins\generic\googleBooks\classes\Jobs\DeliveryJob;
use APP\plugins\generic\googleBooks\classes\Jobs\SubmissionDiscoveryJob;
use APP\plugins\generic\googleBooks\classes\Jobs\SubmissionSyncJob;
use APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixValidator;
use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
use APP\plugins\generic\googleBooks\classes\Security\SecretStore;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use DateTimeImmutable;
use DateTimeZone;
use PKP\config\Config;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\Role;
use RuntimeException;
use Throwable;

final class DashboardHandler extends \APP\handler\Handler
{
    public $_isBackendPage = true;

    public function __construct(private GoogleBooksPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
            [
                'index', 'save', 'saveApi', 'saveFeed', 'saveCrawlerAuth', 'saveTransportAuth', 'saveDelivery', 'saveBehavior',
                'testDelivery', 'deliverNow', 'discover', 'verify', 'sync', 'forceRefresh',
                'syncBook', 'discoverBook', 'setValidation', 'downloadValidation',
            ]
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function initialize($request, $args = null)
    {
        $this->setupTemplate($request);
        parent::initialize($request, $args);
    }

    public function index($args, $request)
    {
        // 0.1.1.0 introduced additional discovery/feed columns but omitted
        // upgrade.xml. When that release was installed over 0.1.0.x, OMP
        // replaced the plugin files without applying the schema migration.
        // Repair that state before any repository query so the dashboard does
        // not fail with an SQL 500 on the first request.
        GoogleBooksSchemaMigration::ensureCurrent();

        $context = $this->requireContext($request);
        $contextId = (int) $context->getId();
        $repository = new GoogleBooksStateRepository();
        $settings = $this->settings($contextId);
        $apiConfigured = $this->plugin->hasGoogleApiKey($contextId);
        $deliveryManager = new DeliveryManager($this->plugin);
        $deliveryReadiness = $deliveryManager->readiness($contextId);
        $feedReady = (bool) $deliveryReadiness['ready'];
        $feedAuthDiagnostic = $this->feedAuthDiagnostic($contextId);
        $deliveryConnectionDiagnostic = $this->safeDiagnosticSetting($contextId, 'deliveryConnectionDiagnostic');
        $deliveryDiagnostic = $this->safeDiagnosticSetting($contextId, 'deliveryDiagnostic');
        $deliveryCapabilities = TransportCapabilities::detect();

        $records = [];
        foreach ($repository->listByContext($contextId) as $record) {
            $submission = Repo::submission()->get((int) $record->submission_id);
            $googleUrl = null;
            if ((string) ($record->discovery_status ?? '') !== 'multiple_matches') {
                $googleUrl = $this->safeHttpUrl((string) ($record->google_info_link ?: $record->google_preview_link));
                if ($googleUrl === null && trim((string) ($record->google_volume_id ?? '')) !== '') {
                    $googleUrl = 'https://books.google.com/books?id=' . rawurlencode((string) $record->google_volume_id);
                }
                if ($googleUrl === null) {
                    $googleUrl = $this->safeHttpUrl((string) ($record->google_self_link ?? ''));
                }
            }
            $isPublished = $submission && (int) $submission->getData('status') === Submission::STATUS_PUBLISHED;
            $records[] = [
                'record' => $record,
                'title' => $submission && $submission->getCurrentPublication()
                    ? (string) $submission->getCurrentPublication()->getLocalizedTitle()
                    : '#' . $record->submission_id,
                'canDiscover' => $isPublished && $apiConfigured,
                'canSync' => $isPublished && $feedReady,
                'googleUrl' => $googleUrl,
            ];
        }

        $published = [];
        foreach (Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany() as $submission) {
            $publication = $submission->getCurrentPublication();
            $published[] = [
                'id' => (int) $submission->getId(),
                'title' => $publication ? (string) $publication->getLocalizedTitle() : '#' . $submission->getId(),
            ];
        }

        $messageCode = (string) $request->getUserVar('message');
        [$messageText, $messageClass] = $this->message($messageCode);
        $incident = preg_replace('/[^A-Z0-9-]/i', '', (string) $request->getUserVar('incident')) ?: '';
        $collectionCodeAttempt = GoogleBooksPlugin::normalizeCollectionCode((string) $request->getUserVar('collectionCodeAttempt'));
        if ($messageCode === 'invalidCollectionCode' && $collectionCodeAttempt !== '') {
            $settings['collectionCode'] = $collectionCodeAttempt;
        }

        $templateMgr = TemplateManager::getManager($request);
        $assetBase = rtrim($request->getBaseUrl(), '/') . '/' . trim($this->plugin->getPluginPath(), '/');
        // Cache-bust dashboard assets on every plugin release. OMP installations
        // are frequently upgraded in-place and browsers/CDNs may otherwise keep
        // the previous dashboard.js under the unchanged plugin path.
        $assetVersion = '0.1.2.9';
        $dashboardCssUrl = $assetBase . '/styles/dashboard.css?v=' . rawurlencode($assetVersion);
        $dashboardJsUrl = $assetBase . '/scripts/dashboard.js?v=' . rawurlencode($assetVersion);
        $templateMgr->addStyleSheet(
            'googleBooksDashboard',
            $dashboardCssUrl,
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE],
        );
        $templateMgr->addJavaScript(
            'googleBooksDashboard',
            $dashboardJsUrl,
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE],
        );

        $templateMgr->assign([
            'googleBooksPlugin' => $this->plugin,
            'googleBooksDashboardJsUrl' => $dashboardJsUrl,
            'googleBooksSettings' => $settings,
            'googleBooksStats' => $repository->stats($contextId),
            'googleBooksPublishedCount' => count($published),
            'googleBooksRecords' => $records,
            'googleBooksLatestDiscoveryRun' => $repository->latestRunByModes($contextId, ['discovery']),
            'googleBooksLatestFeedRun' => $repository->latestRunByModes($contextId, ['feed', 'force_feed']),
            'googleBooksPublished' => $published,
            'googleBooksApiConfigured' => $apiConfigured,
            'googleBooksFeedReady' => $feedReady,
            'googleBooksFeedAuthDiagnostic' => $feedAuthDiagnostic,
            'googleBooksDeliveryReadiness' => $deliveryReadiness,
            'googleBooksDeliveryConnectionDiagnostic' => $deliveryConnectionDiagnostic,
            'googleBooksDeliveryDiagnostic' => $deliveryDiagnostic,
            'googleBooksDeliveryCapabilities' => $deliveryCapabilities,
            'googleBooksDeliveryModes' => DeliveryConfig::modes(),
            'googleBooksCollectionCodes' => $this->plugin->getCollectionCodes($contextId),
            'googleBooksCollectionCodesString' => implode(', ', $this->plugin->getCollectionCodes($contextId)),
            'googleBooksOnixUrl' => $this->feedPageUrl($request, 'onix'),
            'googleBooksEbooksUrl' => $this->feedPageUrl($request, 'ebooks'),
            'googleBooksValidationUrl' => $this->validationUrl($request, $contextId),
            'googleBooksSaveApiUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveApi'),
            'googleBooksSaveFeedUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveFeed'),
            'googleBooksSaveCrawlerAuthUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveCrawlerAuth'),
            'googleBooksSaveTransportAuthUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveTransportAuth'),
            'googleBooksSaveDeliveryUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveDelivery'),
            'googleBooksTestDeliveryUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'testDelivery'),
            'googleBooksDeliverNowUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'deliverNow'),
            'googleBooksSaveBehaviorUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'saveBehavior'),
            'googleBooksDiscoverUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'discover'),
            'googleBooksSyncUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'sync'),
            'googleBooksForceRefreshUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'forceRefresh'),
            'googleBooksDiscoverBookUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'discoverBook'),
            'googleBooksSyncBookUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'syncBook'),
            'googleBooksSetValidationUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'setValidation'),
            'googleBooksDownloadValidationUrl' => $this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, 'downloadValidation'),
            'googleBooksMessageText' => $messageText,
            'googleBooksMessageClass' => $messageClass,
            'googleBooksIncident' => $incident,
            'googleBooksCollectionCodeAttempt' => $collectionCodeAttempt,
            'googleBooksCollectionCodeLength' => strlen($collectionCodeAttempt),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('dashboard.tpl'));
    }

    /**
     * Legacy 0.1.0.x combined form endpoint.
     *
     * Keep this endpoint functional for browsers that still have an older
     * compiled/cached dashboard template after an in-place plugin upgrade.
     * New dashboards use the three independent Save actions below.
     */
    public function save($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistApiSettings($request, $contextId);
            $this->persistFeedSettings($request, $contextId);
            $this->persistBehaviorSettings($request, $contextId);
            $this->redirect($request, 'settingsSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'save', $e);
        }
    }

    public function saveApi($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistApiSettings($request, $contextId);
            $this->redirect($request, 'apiSettingsSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveApi', $e);
        }
    }

    public function saveFeed($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistFeedSettings($request, $contextId);
            $this->redirect($request, 'feedSettingsSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveFeed', $e);
        }
    }

    public function saveCrawlerAuth($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistCrawlerAuthSettings($request, $contextId);
            $this->redirect($request, 'crawlerAuthSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveCrawlerAuth', $e);
        }
    }

    public function saveTransportAuth($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistTransportAuthSettings($request, $contextId);
            $this->redirect($request, 'transportAuthSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveTransportAuth', $e);
        }
    }

    public function saveDelivery($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistDeliverySettings($request, $contextId);
            $this->redirect($request, 'deliverySettingsSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveDelivery', $e);
        }
    }

    public function testDelivery($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $result = (new DeliveryManager($this->plugin))->test($context);
            $this->redirect($request, ($result['status'] ?? '') === 'success' ? 'deliveryTestSucceeded' : 'deliveryTestFailed');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'testDelivery', $e, 'deliveryTestFailed');
        }
    }

    public function deliverNow($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $contextId = (int) $context->getId();
            if (!$this->feedReady($contextId)) {
                $this->redirect($request, 'feedNotReady');
            }
            DeliveryJob::dispatch($contextId, (bool) $request->getUserVar('force'))
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'deliveryQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'deliverNow', $e);
        }
    }

    public function saveBehavior($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            $this->persistBehaviorSettings($request, $contextId);
            $this->redirect($request, 'behaviorSettingsSaved');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'saveBehavior', $e);
        }
    }

    public function discover($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $contextId = (int) $context->getId();
            if (!$this->plugin->hasGoogleApiKey($contextId)) {
                $this->redirect($request, 'apiKeyRequired');
            }
            CatalogDiscoveryJob::dispatch($contextId, $request->getUser()?->getId())
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'discoveryQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'discover', $e);
        }
    }

    /** Backwards-compatible alias. */
    public function verify($args, $request): void
    {
        $this->discover($args, $request);
    }

    public function sync($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $contextId = (int) $context->getId();
            if (!$this->feedReady($contextId)) {
                $this->redirect($request, 'feedNotReady');
            }
            CatalogSyncJob::dispatch($contextId, false, $request->getUser()?->getId())
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'feedSyncQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'sync', $e);
        }
    }

    public function forceRefresh($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            if (!$this->feedReady($contextId)) {
                $this->redirect($request, 'feedNotReady');
            }
            CatalogSyncJob::dispatch($contextId, true, $request->getUser()?->getId())
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'forceQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'forceRefresh', $e);
        }
    }

    public function discoverBook($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $contextId = (int) $context->getId();
            if (!$this->plugin->hasGoogleApiKey($contextId)) {
                $this->redirect($request, 'apiKeyRequired');
            }
            $submissionId = (int) $request->getUserVar('submissionId');
            $this->requireSubmission($contextId, $submissionId);
            SubmissionDiscoveryJob::dispatch($contextId, $submissionId)
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'bookDiscoveryQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'discoverBook', $e);
        }
    }

    public function syncBook($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $contextId = (int) $this->requireContext($request)->getId();
            if (!$this->feedReady($contextId)) {
                $this->redirect($request, 'feedNotReady');
            }
            $submissionId = (int) $request->getUserVar('submissionId');
            $this->requireSubmission($contextId, $submissionId);
            SubmissionSyncJob::dispatch($contextId, $submissionId, (bool) $request->getUserVar('force'))
                ->onConnection($this->backgroundQueueConnection())
                ->delay($this->backgroundQueueDelay());
            $this->redirect($request, 'bookSyncQueued');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'syncBook', $e);
        }
    }

    public function setValidation($args, $request): void
    {
        try {
            $this->requireCsrf($request);
            $context = $this->requireContext($request);
            $contextId = (int) $context->getId();
            $submissionId = (int) $request->getUserVar('validationSubmissionId');
            if ($submissionId <= 0) {
                $this->redirect($request, 'invalidSubmission');
            }
            $xml = (new FeedManifestService($this->plugin))->buildValidationOnix($context, $submissionId);
            $errors = (new GoogleOnixValidator())->validateXml($xml);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }
            $this->plugin->updateSetting($contextId, 'validationSubmissionId', $submissionId, 'int');
            $this->plugin->bumpFeedRevision($contextId);
            $this->redirect($request, 'validationReady');
        } catch (Throwable $e) {
            $this->operationFailure($request, 'setValidation', $e, 'validationFailed');
        }
    }

    public function downloadValidation($args, $request): void
    {
        try {
            $context = $this->requireContext($request);
            $submissionId = (int) $this->plugin->getSetting((int) $context->getId(), 'validationSubmissionId');
            if ($submissionId <= 0) {
                $this->redirect($request, 'validationNotSelected');
            }
            $xml = (new FeedManifestService($this->plugin))->buildValidationOnix($context, $submissionId);
            $errors = (new GoogleOnixValidator())->validateXml($xml);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }
            if (headers_sent()) {
                throw new RuntimeException('Cannot send a complete ONIX validation file after HTTP output has already started.');
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename="googlebooksvalidation' . $submissionId . '.xml"');
            header('Content-Length: ' . strlen($xml));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo $xml;
            exit;
        } catch (Throwable $e) {
            $this->operationFailure($request, 'downloadValidation', $e, 'validationFailed');
        }
    }

    private function persistApiSettings($request, int $contextId): void
    {
        $this->plugin->updateSetting($contextId, 'googlePartnerId', trim((string) $request->getUserVar('googlePartnerId')), 'string');
        $this->plugin->updateSetting($contextId, 'autoDiscovery', (bool) $request->getUserVar('autoDiscovery'), 'bool');
        $this->plugin->updateSetting($contextId, 'showPublicLink', (bool) $request->getUserVar('showPublicLink'), 'bool');
        if ((bool) $request->getUserVar('clearGoogleApiKey')) {
            $this->plugin->clearGoogleApiKey($contextId);
            return;
        }
        $newApiKey = trim((string) $request->getUserVar('googleApiKey'));
        if ($newApiKey !== '') {
            $this->plugin->setGoogleApiKey($contextId, $newApiKey);
        }
    }

    private function persistFeedSettings($request, int $contextId): void
    {
        $collectionCode = GoogleBooksPlugin::normalizeCollectionCode((string) $request->getUserVar('collectionCode'));
        if ($collectionCode !== '' && !GoogleBooksPlugin::isValidCollectionCode($collectionCode)) {
            $this->redirect($request, 'invalidCollectionCode', null, ['collectionCodeAttempt' => $collectionCode]);
        }
        try {
            $imprintMap = $this->parseImprintMap((string) $request->getUserVar('imprintCollectionMap'));
        } catch (RuntimeException) {
            $this->redirect($request, 'invalidImprintMap');
        }

        $feedUsername = trim((string) $request->getUserVar('feedUsername'));
        $newPassword = trim((string) $request->getUserVar('feedPassword'));
        if ($feedUsername !== '' && !preg_match('/^[A-Za-z0-9]+$/', $feedUsername)) {
            $this->redirect($request, 'invalidFeedCredentials');
        }
        if ($newPassword !== '' && !preg_match('/^[A-Za-z0-9]+$/', $newPassword)) {
            $this->redirect($request, 'invalidFeedCredentials');
        }

        $feedEnabled = (bool) $request->getUserVar('feedEnabled');
        $existingPasswordHash = (string) $this->plugin->getSetting($contextId, 'feedPasswordHash');
        if ($feedEnabled && (($collectionCode === '' && $imprintMap === []) || $feedUsername === '' || ($newPassword === '' && $existingPasswordHash === ''))) {
            $this->redirect($request, 'feedConfigurationIncomplete');
        }

        $this->plugin->updateSetting($contextId, 'collectionCode', $collectionCode, 'string');
        $this->plugin->updateSetting($contextId, 'feedUsername', $feedUsername, 'string');
        $this->plugin->updateSetting(
            $contextId,
            'imprintCollectionMap',
            json_encode($imprintMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'string',
        );
        $this->plugin->updateSetting($contextId, 'feedEnabled', $feedEnabled, 'bool');
        if ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash the feed password.');
            }
            $this->plugin->updateSetting($contextId, 'feedPasswordHash', $hash, 'string');
        }
        // Any credential/configuration change makes the previous authentication
        // diagnostic stale. A fresh feed request will repopulate it safely.
        $this->plugin->updateSetting($contextId, 'feedAuthDiagnostic', '', 'string');
        $this->plugin->bumpFeedRevision($contextId);
    }

    private function persistCrawlerAuthSettings($request, int $contextId): void
    {
        $feedUsername = trim((string) $request->getUserVar('feedUsername'));
        $newPassword = trim((string) $request->getUserVar('feedPassword'));
        if ($feedUsername !== '' && !preg_match('/^[A-Za-z0-9]+$/', $feedUsername)) {
            $this->redirect($request, 'invalidFeedCredentials');
        }
        if ($newPassword !== '' && !preg_match('/^[A-Za-z0-9]+$/', $newPassword)) {
            $this->redirect($request, 'invalidFeedCredentials');
        }

        $this->plugin->updateSetting($contextId, 'feedUsername', $feedUsername, 'string');
        if ((bool) $request->getUserVar('clearFeedPassword')) {
            $this->plugin->updateSetting($contextId, 'feedPasswordHash', '', 'string');
        } elseif ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash the feed password.');
            }
            $this->plugin->updateSetting($contextId, 'feedPasswordHash', $hash, 'string');
        }
        $this->plugin->updateSetting($contextId, 'feedAuthDiagnostic', '', 'string');
    }

    private function persistTransportAuthSettings($request, int $contextId): void
    {
        foreach (['googleSftp', 'publisherSftp'] as $prefix) {
            $this->plugin->updateSetting($contextId, $prefix . 'Username', trim((string) $request->getUserVar($prefix . 'Username')), 'string');
            $authMode = (string) $request->getUserVar($prefix . 'AuthMode');
            if (!in_array($authMode, ['password', 'private_key'], true)) {
                $authMode = 'password';
            }
            $this->plugin->updateSetting($contextId, $prefix . 'AuthMode', $authMode, 'string');
            $this->persistEncryptedSecret($request, $contextId, $prefix . 'PasswordEncrypted', $prefix . 'Password', 'clear' . ucfirst($prefix) . 'Password');
            $this->persistEncryptedSecret($request, $contextId, $prefix . 'PrivateKeyEncrypted', $prefix . 'PrivateKey', 'clear' . ucfirst($prefix) . 'PrivateKey');
            $this->persistEncryptedSecret($request, $contextId, $prefix . 'PrivateKeyPassphraseEncrypted', $prefix . 'PrivateKeyPassphrase', 'clear' . ucfirst($prefix) . 'PrivateKeyPassphrase');
        }

        $this->plugin->updateSetting($contextId, 'publisherFtpUsername', trim((string) $request->getUserVar('publisherFtpUsername')), 'string');
        $this->persistEncryptedSecret($request, $contextId, 'publisherFtpPasswordEncrypted', 'publisherFtpPassword', 'clearPublisherFtpPassword');

        $serviceAccount = trim((string) $request->getUserVar('gcsServiceAccountJson'));
        if ((bool) $request->getUserVar('clearGcsServiceAccountJson')) {
            $this->plugin->updateSetting($contextId, 'gcsServiceAccountEncrypted', '', 'string');
        } elseif ($serviceAccount !== '') {
            $decoded = json_decode($serviceAccount, true);
            if (!is_array($decoded) || trim((string) ($decoded['client_email'] ?? '')) === '' || trim((string) ($decoded['private_key'] ?? '')) === '') {
                throw new RuntimeException('The Google Cloud service account JSON must contain client_email and private_key.');
            }
            $this->plugin->updateSetting($contextId, 'gcsServiceAccountEncrypted', SecretStore::encrypt($serviceAccount), 'string');
        }
        $this->plugin->updateSetting($contextId, 'deliveryConnectionDiagnostic', '', 'string');
    }

    private function persistDeliverySettings($request, int $contextId): void
    {
        $mode = trim((string) $request->getUserVar('deliveryMode'));
        if (!in_array($mode, DeliveryConfig::modes(), true)) {
            throw new RuntimeException('Unsupported Google Books delivery mode.');
        }

        $collectionCode = GoogleBooksPlugin::normalizeCollectionCode((string) $request->getUserVar('collectionCode'));
        if ($collectionCode !== '' && !GoogleBooksPlugin::isValidCollectionCode($collectionCode)) {
            $this->redirect($request, 'invalidCollectionCode', null, ['collectionCodeAttempt' => $collectionCode]);
        }
        try {
            $imprintMap = $this->parseImprintMap((string) $request->getUserVar('imprintCollectionMap'));
        } catch (RuntimeException) {
            $this->redirect($request, 'invalidImprintMap');
        }

        $this->plugin->updateSetting($contextId, 'collectionCode', $collectionCode, 'string');
        $this->plugin->updateSetting(
            $contextId,
            'imprintCollectionMap',
            json_encode($imprintMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'string',
        );
        $this->plugin->updateSetting($contextId, 'deliveryMode', $mode, 'string');
        $this->plugin->updateSetting($contextId, 'feedEnabled', (bool) $request->getUserVar('feedEnabled'), 'bool');
        foreach (['deliverOnixFull', 'deliverOnixRights', 'deliverEbooks', 'deliverValidation'] as $name) {
            $this->plugin->updateSetting($contextId, $name, (bool) $request->getUserVar($name), 'bool');
        }

        foreach (['googleSftp', 'publisherSftp'] as $prefix) {
            $port = (int) $request->getUserVar($prefix . 'Port');
            $endpoint = SftpEndpoint::parse(
                (string) $request->getUserVar($prefix . 'Host'),
                $port > 0 ? $port : 22,
                (string) $request->getUserVar($prefix . 'RemoteRoot'),
            );
            $this->plugin->updateSetting($contextId, $prefix . 'Host', $endpoint['host'], 'string');
            $this->plugin->updateSetting($contextId, $prefix . 'Port', $endpoint['port'], 'int');
            $this->plugin->updateSetting($contextId, $prefix . 'RemoteRoot', $endpoint['remoteRoot'], 'string');
            $this->plugin->updateSetting($contextId, $prefix . 'HostKeyFingerprint', trim((string) $request->getUserVar($prefix . 'HostKeyFingerprint')), 'string');
        }

        $this->plugin->updateSetting($contextId, 'publisherFtpHost', trim((string) $request->getUserVar('publisherFtpHost')), 'string');
        $ftpPort = (int) $request->getUserVar('publisherFtpPort');
        $this->plugin->updateSetting($contextId, 'publisherFtpPort', $ftpPort > 0 ? $ftpPort : 21, 'int');
        $this->plugin->updateSetting($contextId, 'publisherFtpRemoteRoot', trim((string) $request->getUserVar('publisherFtpRemoteRoot')), 'string');
        $this->plugin->updateSetting($contextId, 'publisherFtpTls', (bool) $request->getUserVar('publisherFtpTls'), 'bool');
        $this->plugin->updateSetting($contextId, 'publisherFtpPassive', (bool) $request->getUserVar('publisherFtpPassive'), 'bool');

        $bucket = trim((string) $request->getUserVar('gcsBucket'));
        if ($bucket !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]{1,221}[a-z0-9]$/', $bucket)) {
            throw new RuntimeException('Invalid Google Cloud Storage bucket name.');
        }
        $this->plugin->updateSetting($contextId, 'gcsBucket', $bucket, 'string');
        $this->plugin->updateSetting($contextId, 'gcsPrefix', trim((string) $request->getUserVar('gcsPrefix'), '/'), 'string');
        $this->plugin->updateSetting($contextId, 'gcsGoogleReaderServiceAccount', trim((string) $request->getUserVar('gcsGoogleReaderServiceAccount')), 'string');

        $this->plugin->updateSetting($contextId, 'deliveryConnectionDiagnostic', '', 'string');
        $this->plugin->updateSetting($contextId, 'deliveryDiagnostic', '', 'string');
        $this->plugin->bumpFeedRevision($contextId);
    }

    private function persistEncryptedSecret($request, int $contextId, string $setting, string $field, string $clearField): void
    {
        if ((bool) $request->getUserVar($clearField)) {
            $this->plugin->updateSetting($contextId, $setting, '', 'string');
            return;
        }
        $value = (string) $request->getUserVar($field);
        if (trim($value) !== '') {
            $this->plugin->updateSetting($contextId, $setting, SecretStore::encrypt($value), 'string');
        }
    }

    private function persistBehaviorSettings($request, int $contextId): void
    {
        foreach (['autoSync', 'autoVerifyGoogle', 'defaultFreeOfCharge', 'defaultWorldwideRightsForFree'] as $name) {
            $this->plugin->updateSetting($contextId, $name, (bool) $request->getUserVar($name), 'bool');
        }
    }

    /** @return array<string,mixed> */
    private function settings(int $contextId): array
    {
        $mapText = [];
        foreach ($this->plugin->getImprintCollectionMap($contextId) as $imprint => $code) {
            $mapText[] = $imprint . '=' . $code;
        }
        return [
            'collectionCode' => (string) $this->plugin->getSetting($contextId, 'collectionCode'),
            'googleApiKey' => '',
            'hasGoogleApiKey' => $this->plugin->hasGoogleApiKey($contextId),
            'googlePartnerId' => (string) $this->plugin->getSetting($contextId, 'googlePartnerId'),
            'feedUsername' => (string) $this->plugin->getSetting($contextId, 'feedUsername'),
            'hasFeedPassword' => (string) $this->plugin->getSetting($contextId, 'feedPasswordHash') !== '',
            'imprintCollectionMap' => implode("\n", $mapText),
            'autoDiscovery' => $this->plugin->boolSetting($contextId, 'autoDiscovery', true),
            'autoSync' => $this->plugin->boolSetting($contextId, 'autoSync', false),
            'autoVerifyGoogle' => $this->plugin->boolSetting($contextId, 'autoVerifyGoogle', true),
            'defaultFreeOfCharge' => $this->plugin->boolSetting($contextId, 'defaultFreeOfCharge', false),
            'defaultWorldwideRightsForFree' => $this->plugin->boolSetting($contextId, 'defaultWorldwideRightsForFree', false),
            'showPublicLink' => $this->plugin->boolSetting($contextId, 'showPublicLink', true),
            'feedEnabled' => $this->plugin->boolSetting($contextId, 'feedEnabled', false),
            'validationSubmissionId' => (int) $this->plugin->getSetting($contextId, 'validationSubmissionId'),
            'deliveryMode' => DeliveryConfig::mode($this->plugin, $contextId),
            'deliverOnixFull' => $this->plugin->boolSetting($contextId, 'deliverOnixFull', true),
            'deliverOnixRights' => $this->plugin->boolSetting($contextId, 'deliverOnixRights', true),
            'deliverEbooks' => $this->plugin->boolSetting($contextId, 'deliverEbooks', true),
            'deliverValidation' => $this->plugin->boolSetting($contextId, 'deliverValidation', true),

            'googleSftpHost' => (string) $this->plugin->getSetting($contextId, 'googleSftpHost'),
            'googleSftpPort' => (int) ($this->plugin->getSetting($contextId, 'googleSftpPort') ?: 22),
            'googleSftpUsername' => (string) $this->plugin->getSetting($contextId, 'googleSftpUsername'),
            'googleSftpAuthMode' => (string) ($this->plugin->getSetting($contextId, 'googleSftpAuthMode') ?: 'password'),
            'googleSftpRemoteRoot' => (string) $this->plugin->getSetting($contextId, 'googleSftpRemoteRoot'),
            'googleSftpHostKeyFingerprint' => (string) $this->plugin->getSetting($contextId, 'googleSftpHostKeyFingerprint'),
            'hasGoogleSftpPassword' => (string) $this->plugin->getSetting($contextId, 'googleSftpPasswordEncrypted') !== '',
            'hasGoogleSftpPrivateKey' => (string) $this->plugin->getSetting($contextId, 'googleSftpPrivateKeyEncrypted') !== '',
            'hasGoogleSftpPrivateKeyPassphrase' => (string) $this->plugin->getSetting($contextId, 'googleSftpPrivateKeyPassphraseEncrypted') !== '',

            'publisherSftpHost' => (string) $this->plugin->getSetting($contextId, 'publisherSftpHost'),
            'publisherSftpPort' => (int) ($this->plugin->getSetting($contextId, 'publisherSftpPort') ?: 22),
            'publisherSftpUsername' => (string) $this->plugin->getSetting($contextId, 'publisherSftpUsername'),
            'publisherSftpAuthMode' => (string) ($this->plugin->getSetting($contextId, 'publisherSftpAuthMode') ?: 'password'),
            'publisherSftpRemoteRoot' => (string) $this->plugin->getSetting($contextId, 'publisherSftpRemoteRoot'),
            'publisherSftpHostKeyFingerprint' => (string) $this->plugin->getSetting($contextId, 'publisherSftpHostKeyFingerprint'),
            'hasPublisherSftpPassword' => (string) $this->plugin->getSetting($contextId, 'publisherSftpPasswordEncrypted') !== '',
            'hasPublisherSftpPrivateKey' => (string) $this->plugin->getSetting($contextId, 'publisherSftpPrivateKeyEncrypted') !== '',
            'hasPublisherSftpPrivateKeyPassphrase' => (string) $this->plugin->getSetting($contextId, 'publisherSftpPrivateKeyPassphraseEncrypted') !== '',

            'publisherFtpHost' => (string) $this->plugin->getSetting($contextId, 'publisherFtpHost'),
            'publisherFtpPort' => (int) ($this->plugin->getSetting($contextId, 'publisherFtpPort') ?: 21),
            'publisherFtpUsername' => (string) $this->plugin->getSetting($contextId, 'publisherFtpUsername'),
            'publisherFtpRemoteRoot' => (string) $this->plugin->getSetting($contextId, 'publisherFtpRemoteRoot'),
            'publisherFtpTls' => $this->plugin->boolSetting($contextId, 'publisherFtpTls', false),
            'publisherFtpPassive' => $this->plugin->boolSetting($contextId, 'publisherFtpPassive', true),
            'hasPublisherFtpPassword' => (string) $this->plugin->getSetting($contextId, 'publisherFtpPasswordEncrypted') !== '',

            'gcsBucket' => (string) $this->plugin->getSetting($contextId, 'gcsBucket'),
            'gcsPrefix' => (string) $this->plugin->getSetting($contextId, 'gcsPrefix'),
            'gcsGoogleReaderServiceAccount' => (string) $this->plugin->getSetting($contextId, 'gcsGoogleReaderServiceAccount'),
            'hasGcsServiceAccountJson' => (string) $this->plugin->getSetting($contextId, 'gcsServiceAccountEncrypted') !== '',
        ];
    }

    /** @return array<string,string> */
    private function parseImprintMap(string $raw): array
    {
        $map = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException('Invalid imprint map.');
            }
            [$imprint, $code] = array_map('trim', explode('=', $line, 2));
            $code = GoogleBooksPlugin::normalizeCollectionCode($code);
            if ($imprint === '' || !GoogleBooksPlugin::isValidCollectionCode($code)) {
                throw new RuntimeException('Invalid imprint map.');
            }
            $map[$imprint] = $code;
        }
        return $map;
    }

    /** @return array{0:?string,1:string} */
    private function message(string $code): array
    {
        $messages = [
            'settingsSaved' => ['plugins.generic.googleBooks.message.settingsSaved', 'success'],
            'apiSettingsSaved' => ['plugins.generic.googleBooks.message.apiSettingsSaved', 'success'],
            'feedSettingsSaved' => ['plugins.generic.googleBooks.message.feedSettingsSaved', 'success'],
            'crawlerAuthSaved' => ['plugins.generic.googleBooks.message.crawlerAuthSaved', 'success'],
            'transportAuthSaved' => ['plugins.generic.googleBooks.message.transportAuthSaved', 'success'],
            'deliverySettingsSaved' => ['plugins.generic.googleBooks.message.deliverySettingsSaved', 'success'],
            'deliveryTestSucceeded' => ['plugins.generic.googleBooks.message.deliveryTestSucceeded', 'success'],
            'deliveryTestFailed' => ['plugins.generic.googleBooks.message.deliveryTestFailed', 'error'],
            'deliveryQueued' => ['plugins.generic.googleBooks.message.deliveryQueued', 'success'],
            'behaviorSettingsSaved' => ['plugins.generic.googleBooks.message.behaviorSettingsSaved', 'success'],
            'discoveryQueued' => ['plugins.generic.googleBooks.message.discoveryQueued', 'success'],
            'feedSyncQueued' => ['plugins.generic.googleBooks.message.feedSyncQueued', 'success'],
            'forceQueued' => ['plugins.generic.googleBooks.message.forceQueued', 'success'],
            'bookDiscoveryQueued' => ['plugins.generic.googleBooks.message.bookDiscoveryQueued', 'success'],
            'bookSyncQueued' => ['plugins.generic.googleBooks.message.bookSyncQueued', 'success'],
            'validationReady' => ['plugins.generic.googleBooks.message.validationReady', 'success'],
            'invalidCollectionCode' => ['plugins.generic.googleBooks.message.invalidCollectionCode', 'error'],
            'invalidFeedCredentials' => ['plugins.generic.googleBooks.message.invalidFeedCredentials', 'error'],
            'invalidImprintMap' => ['plugins.generic.googleBooks.message.invalidImprintMap', 'error'],
            'feedConfigurationIncomplete' => ['plugins.generic.googleBooks.message.feedConfigurationIncomplete', 'error'],
            'feedNotReady' => ['plugins.generic.googleBooks.message.feedNotReady', 'error'],
            'apiKeyRequired' => ['plugins.generic.googleBooks.message.apiKeyRequired', 'error'],
            'invalidSubmission' => ['plugins.generic.googleBooks.message.invalidSubmission', 'error'],
            'validationNotSelected' => ['plugins.generic.googleBooks.message.validationNotSelected', 'error'],
            'validationFailed' => ['plugins.generic.googleBooks.message.validationFailed', 'error'],
            'csrfExpired' => ['plugins.generic.googleBooks.message.csrfExpired', 'error'],
            'operationFailed' => ['plugins.generic.googleBooks.message.operationFailed', 'error'],
        ];
        if (!isset($messages[$code])) {
            return [null, ''];
        }
        return [__($messages[$code][0]), $messages[$code][1]];
    }

    private function requireContext($request): object
    {
        $context = $request->getContext();
        if (!$context) {
            throw new RuntimeException('Google Books operation requires a press context.');
        }
        return $context;
    }

    private function requireSubmission(int $contextId, int $submissionId): Submission
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== $contextId || (int) $submission->getData('status') !== Submission::STATUS_PUBLISHED) {
            throw new RuntimeException('The selected submission is not a published book in this press.');
        }
        return $submission;
    }

    /** @return array<string,mixed>|null */
    private function feedAuthDiagnostic(int $contextId): ?array
    {
        $raw = trim((string) $this->plugin->getSetting($contextId, 'feedAuthDiagnostic'));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $allowed = [
            'timestamp', 'status', 'authenticated', 'credentialSource',
            'authorizationPresent', 'authorizationSource', 'authorizationIsBasic',
            'authorizationDecoded', 'nativeUserPresent', 'nativePasswordPresent',
            'usernamePresent', 'passwordPresent', 'configuredUsernamePresent',
            'configuredPasswordHashPresent', 'usernameMatches', 'passwordMatches',
        ];
        return array_intersect_key($decoded, array_flip($allowed));
    }

    /** @return array<string,mixed>|null */
    private function safeDiagnosticSetting(int $contextId, string $name): ?array
    {
        $raw = trim((string) $this->plugin->getSetting($contextId, $name));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        unset($decoded['username'], $decoded['password'], $decoded['authorization'], $decoded['serviceAccountJson'], $decoded['privateKey']);
        return $decoded;
    }

    private function feedReady(int $contextId): bool
    {
        return (bool) (new DeliveryManager($this->plugin))->readiness($contextId)['ready'];
    }

    private function safeHttpUrl(string $url): ?string
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function validationUrl($request, int $contextId): ?string
    {
        $submissionId = (int) $this->plugin->getSetting($contextId, 'validationSubmissionId');
        if (!$submissionId) {
            return null;
        }
        return $this->feedPageUrl($request, 'onix', ['validate', 'googlebooksvalidation' . $submissionId . '.xml']);
    }

    private function requireCsrf($request): void
    {
        if (!$request->checkCSRF()) {
            $this->redirect($request, 'csrfExpired');
        }
    }

    private function operationFailure($request, string $operation, Throwable $error, string $message = 'operationFailed'): never
    {
        $incident = gmdate('YmdHis') . '-' . strtoupper(substr(hash('sha256', $operation . '|' . microtime(true) . '|' . mt_rand()), 0, 8));
        error_log(sprintf(
            "[GoogleBooksPlugin][%s][%s] %s: %s in %s:%d\n%s",
            $incident,
            $operation,
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString(),
        ));
        $this->redirect($request, $message, $incident);
    }

    /**
     * Manual dashboard actions must never execute a large Google Books job in
     * the POST request. OMP normally uses its database queue, but publishers
     * can configure Laravel's synchronous queue. Force only that unsafe mode
     * back to OMP's built-in database queue and make the job available a few
     * seconds later so the redirect response can complete before the shutdown
     * JobRunner considers it.
     */
    private function backgroundQueueConnection(): string
    {
        $configured = trim((string) Config::getVar('queues', 'default_connection', 'database'));
        return strtolower($configured) === 'sync' || $configured === '' ? 'database' : $configured;
    }

    private function backgroundQueueDelay(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 seconds');
    }

    /** @param array<string,scalar> $extraParams */
    private function redirect($request, string $message, ?string $incident = null, array $extraParams = []): never
    {
        $params = array_merge(['message' => $message], $extraParams);
        if ($incident !== null && $incident !== '') {
            $params['incident'] = $incident;
        }
        $request->redirectUrl($this->pageUrl($request, GoogleBooksPlugin::DASHBOARD_PAGE, null, null, $params));
        exit;
    }

    private function pageUrl($request, string $page, ?string $op = null, ?array $path = null, ?array $params = null): string
    {
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context?->getPath(),
            $page,
            $op,
            $path,
            $params,
            null,
            false,
            null,
        );
    }

    private function feedPageUrl($request, ?string $op = null, ?array $path = null): string
    {
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context?->getPath(),
            GoogleBooksPlugin::FEED_PAGE,
            $op,
            $path,
            null,
            null,
            false,
            $context?->getPrimaryLocale(),
        );
    }
}
