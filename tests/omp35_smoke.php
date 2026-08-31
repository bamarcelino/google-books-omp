<?php

declare(strict_types=1);

/**
 * OMP 3.5 contract smoke test.
 *
 * This test loads every plugin class against small API-compatible stubs for
 * the OMP/PKP parent classes used at declaration time. It then exercises the
 * plugin registration path, page handlers, queue-job construction and schema
 * migration declarations. The pure Google API, identifier and ONIX behavior
 * is covered separately by tests/run.php.
 */

namespace PKP\config {
    final class Config
    {
        public static function getVar(string $section, string $name, mixed $default = null): mixed
        {
            if ($section === 'queues' && $name === 'default_connection') {
                return getenv('GOOGLEBOOKS_QUEUE_CONNECTION') ?: 'database';
            }
            if ($section === 'general' && $name === 'app_key') {
                return 'omp35-smoke-app-key';
            }
            if ($section === 'files' && $name === 'files_dir') {
                return sys_get_temp_dir();
            }
            return $default;
        }
    }
}

namespace PKP\plugins {
    class GenericPlugin
    {
        /** @var array<string,mixed> */
        protected array $settings = [];

        public function register($category, $path, $mainContextId = null)
        {
            return true;
        }

        public function getEnabled($contextId = null): bool
        {
            return true;
        }

        public function addLocaleData(): void
        {
        }

        public function getActions($request, $actionArgs)
        {
            return [];
        }

        public function getSetting($contextId, $name): mixed
        {
            return $this->settings[(string) $contextId . ':' . $name] ?? null;
        }

        public function updateSetting($contextId, $name, $value, $type = null): void
        {
            $this->settings[(string) $contextId . ':' . $name] = $value;
            $capture = getenv('GOOGLEBOOKS_SETTINGS_CAPTURE') ?: '';
            if ($capture !== '') {
                file_put_contents($capture, json_encode([
                    'contextId' => $contextId,
                    'name' => $name,
                    'value' => $value,
                    'type' => $type,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            }
        }

        public function getTemplateResource($template = null, $inCore = false): string
        {
            return 'plugin:' . (string) $template;
        }
    }

    final class Hook
    {
        public const SEQUENCE_CORE = 0;
        public const SEQUENCE_NORMAL = 256;
        public const SEQUENCE_LATE = 512;
        public const SEQUENCE_LAST = 768;

        /** @var array<int,array{0:string,1:mixed,2:int}> */
        public static array $hooks = [];

        public static function add(string $name, mixed $callback, int $sequence = self::SEQUENCE_NORMAL): void
        {
            self::$hooks[] = [$name, $callback, $sequence];
        }
    }

    final class PluginRegistry
    {
        public static function getPlugin(string $category, string $name): mixed
        {
            return null;
        }

        public static function loadPlugin(string $category, string $name, ?int $contextId = null): mixed
        {
            return null;
        }
    }
}

namespace APP\core {
    final class Application
    {
        public const ROUTE_PAGE = 1;
        public const ASSOC_TYPE_PUBLICATION_FORMAT = 519;

        public static function isUnderMaintenance(): bool
        {
            return false;
        }

        public static function isInstalled(): bool
        {
            return true;
        }

        public static function getContextDAO(): object
        {
            return (object) ['primaryKeyColumn' => 'press_id', 'tableName' => 'presses'];
        }
    }
}

namespace Illuminate\Support\Facades {
    final class Event
    {
        /** @var array<int,array{0:string,1:mixed}> */
        public static array $listeners = [];

        public static function listen(string $event, mixed $callback): void
        {
            self::$listeners[] = [$event, $callback];
        }
    }

    final class Schema
    {
        /** @var array<string,\Illuminate\Database\Schema\Blueprint> */
        public static array $tables = [];

        public static function hasTable(string $name): bool
        {
            return isset(self::$tables[$name]);
        }

        public static function create(string $name, callable $callback): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint($name);
            $callback($blueprint);
            self::$tables[$name] = $blueprint;
        }

        public static function hasColumn(string $table, string $column): bool
        {
            return isset(self::$tables[$table]) && in_array($column, self::$tables[$table]->columns, true);
        }

        public static function table(string $name, callable $callback): void
        {
            $blueprint = self::$tables[$name] ?? new \Illuminate\Database\Schema\Blueprint($name);
            $callback($blueprint);
            self::$tables[$name] = $blueprint;
        }

        public static function dropIfExists(string $name): void
        {
            unset(self::$tables[$name]);
        }
    }
}

namespace Illuminate\Database\Migrations {
    abstract class Migration
    {
    }
}

namespace Illuminate\Database\Schema {
    final class FluentDefinition
    {
        public function nullable(): self
        {
            return $this;
        }

        public function default(mixed $value): self
        {
            return $this;
        }

        public function references(string $column): self
        {
            return $this;
        }

        public function on(string $table): self
        {
            return $this;
        }

        public function onDelete(string $action): self
        {
            return $this;
        }
    }

    final class Blueprint
    {
        /** @var array<int,array{0:array<int,string>,1:?string}> */
        public array $uniqueKeys = [];
        /** @var string[] */
        public array $columns = [];

        public function __construct(public string $table)
        {
        }

        private function column(string $name): FluentDefinition
        {
            $this->columns[] = $name;
            return new FluentDefinition();
        }

        public function bigIncrements(string $name): FluentDefinition { return $this->column($name); }
        public function bigInteger(string $name): FluentDefinition { return $this->column($name); }
        public function integer(string $name): FluentDefinition { return $this->column($name); }
        public function boolean(string $name): FluentDefinition { return $this->column($name); }
        public function string(string $name, ?int $length = null): FluentDefinition { return $this->column($name); }
        public function text(string $name): FluentDefinition { return $this->column($name); }
        public function dateTime(string $name): FluentDefinition { return $this->column($name); }

        /** @param string[] $columns */
        public function unique(array $columns, ?string $name = null): void
        {
            $this->uniqueKeys[] = [$columns, $name];
        }

        /** @param string[] $columns */
        public function index(array $columns, ?string $name = null): void
        {
        }

        public function foreign(string $column, ?string $name = null): FluentDefinition
        {
            return new FluentDefinition();
        }
    }
}

namespace APP\handler {
    class Handler
    {
        /** @var array<int,array{0:mixed,1:mixed}> */
        public array $roleAssignments = [];
        public bool $restrictedSite = true;

        public function __construct()
        {
        }

        public function addRoleAssignment($roles, $operations): void
        {
            $this->roleAssignments[] = [$roles, $operations];
        }

        public function setEnforceRestrictedSite($value): void
        {
            $this->restrictedSite = (bool) $value;
        }

        public function addPolicy($policy): void
        {
        }

        public function authorize($request, &$args, $roleAssignments)
        {
            return true;
        }

        public function setupTemplate($request): void
        {
        }

        public function initialize($request, $args = null)
        {
        }
    }
}

namespace PKP\jobs {
    final class PendingDispatch
    {
        public ?string $connection = null;

        public function onConnection(string $connection): self
        {
            $this->connection = $connection;
            $capture = getenv('GOOGLEBOOKS_QUEUE_CAPTURE') ?: '';
            if ($capture !== '') {
                file_put_contents($capture, 'connection=' . $connection . "\n", FILE_APPEND);
            }
            return $this;
        }

        public function delay(mixed $when): self
        {
            $capture = getenv('GOOGLEBOOKS_QUEUE_CAPTURE') ?: '';
            if ($capture !== '') {
                file_put_contents($capture, 'delayed=1' . "\n", FILE_APPEND);
            }
            return $this;
        }
    }

    abstract class BaseJob
    {
        // Mirrors OMP 3.5.0-5 / pkp-lib BaseJob property types.
        public $tries = 3;
        public int $backoff = 5;
        public int $timeout = 60;
        public int $maxExceptions = 3;
        public bool $failOnTimeout = false;
        public ?string $connection = null;
        public ?string $queue = null;

        public function __construct()
        {
            $this->connection = 'database';
            $this->queue = 'queue';
        }

        public static function dispatch(...$arguments): PendingDispatch
        {
            return new PendingDispatch();
        }

        public static function dispatchAfterResponse(...$arguments): PendingDispatch
        {
            return new PendingDispatch();
        }

        abstract public function handle();
    }
}

namespace PKP\security {
    final class Role
    {
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_SITE_ADMIN = 1;
    }
}

namespace PKP\security\authorization {
    final class PKPSiteAccessPolicy
    {
        public function __construct(...$arguments)
        {
        }
    }
}

namespace PKP\linkAction {
    final class LinkAction
    {
        /** @var array<int,mixed> */
        public array $arguments;

        public function __construct(...$arguments)
        {
            $this->arguments = $arguments;
        }
    }
}

namespace PKP\linkAction\request {
    final class RedirectAction
    {
        public string $url;

        public function __construct(string $url, ...$arguments)
        {
            $this->url = $url;
        }
    }
}

namespace PKP\observers\events {
    class PublicationPublished
    {
    }

    class MetadataChanged
    {
    }

    class PublicationUnpublished
    {
    }
}

namespace {
    if (!function_exists('__')) {
        function __(string $key, array $params = []): string
        {
            return $key;
        }
    }

    final class ComponentRouteContext
    {
        public function getPath(): string
        {
            return 'press';
        }

        public function getPrimaryLocale(): string
        {
            return 'pt_BR';
        }
    }

    final class ComponentRouteDispatcher
    {
        /** @var array<int,array<int,mixed>> */
        public array $calls = [];

        public function url($request, $route, $context, $page, $op = null, $path = null, $params = null, $anchor = null, $escape = false, $urlLocaleForPage = null): string
        {
            $this->calls[] = func_get_args();
            $segments = ['https://press.example', $context];
            if ($urlLocaleForPage !== '') { $segments[] = $urlLocaleForPage ?: 'pt_BR'; }
            $segments[] = $page;
            if ($op) { $segments[] = $op; }
            if (is_array($path)) { $segments = array_merge($segments, $path); }
            $url = implode('/', array_map(static fn ($part): string => trim((string) $part, '/'), $segments));
            if ($params) { $url .= '?' . http_build_query($params); }
            return $url;
        }
    }

    final class ComponentRouteRequest
    {
        public int $legacyUrlCalls = 0;
        public ComponentRouteDispatcher $dispatcher;
        public ComponentRouteContext $context;

        public function __construct()
        {
            $this->dispatcher = new ComponentRouteDispatcher();
            $this->context = new ComponentRouteContext();
        }

        public function getContext(): ComponentRouteContext
        {
            return $this->context;
        }

        public function getDispatcher(): ComponentRouteDispatcher
        {
            return $this->dispatcher;
        }

        public function url(...$arguments): string
        {
            $this->legacyUrlCalls++;
            return 'https://press.example/press/$$$call$$$/goog/fetch-grid';
        }
    }

    final class OperationContext
    {
        public function getId(): int { return 1; }
        public function getPath(): string { return 'press'; }
        public function getPrimaryLocale(): string { return 'pt_BR'; }
    }

    final class OperationDispatcher
    {
        public function url($request, $route, $context, $page, $op = null, $path = null, $params = null, $anchor = null, $escape = false, $urlLocaleForPage = null): string
        {
            $segments = ['https://press.example', $context];
            if ($urlLocaleForPage !== '') { $segments[] = $urlLocaleForPage ?: 'pt_BR'; }
            $segments[] = $page;
            if ($op) { $segments[] = $op; }
            if (is_array($path)) { $segments = array_merge($segments, $path); }
            $url = implode('/', array_map(static fn ($part): string => trim((string) $part, '/'), $segments));
            if ($params) { $url .= '?' . http_build_query($params); }
            return $url;
        }
    }

    final class OperationRequest
    {
        public OperationContext $context;
        public OperationDispatcher $dispatcher;

        /** @param array<string,mixed> $vars */
        public function __construct(public array $vars = [], public bool $csrfValid = true)
        {
            $this->context = new OperationContext();
            $this->dispatcher = new OperationDispatcher();
        }

        public function getContext(): OperationContext { return $this->context; }
        public function getDispatcher(): OperationDispatcher { return $this->dispatcher; }
        public function getUserVar(string $name): mixed { return $this->vars[$name] ?? null; }
        public function checkCSRF(): bool { return $this->csrfValid; }
        public function isPost(): bool { return true; }
        public function getUser(): mixed { return null; }
        public function redirectUrl(string $url): void { echo $url . "\n"; }
    }

    $root = dirname(__DIR__);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    // The real OMP runtime resolves namespaced plugin classes through its
    // autoloader. This standalone smoke test requires files directly, so load
    // the plugin's abstract queue base before concrete jobs.
    $jobBase = $root . '/classes/Jobs/GoogleBooksJob.php';
    if (is_file($jobBase)) {
        require_once $jobBase;
    }
    foreach ($files as $file) {
        if ($file === $jobBase) {
            continue;
        }
        require_once $file;
    }

    $operationSmoke = getenv('GOOGLEBOOKS_OPERATION_SMOKE') ?: '';
    if ($operationSmoke !== '') {
        $plugin = new \APP\plugins\generic\googleBooks\GoogleBooksPlugin();
        $handler = new \APP\plugins\generic\googleBooks\classes\DashboardHandler($plugin);
        if ($operationSmoke === 'save-api') {
            $handler->saveApi([], new OperationRequest([
                'googlePartnerId' => 'partner-42',
                'autoDiscovery' => '1',
                'showPublicLink' => '1',
                'googleApiKey' => 'api-key-value',
            ], true));
        } elseif ($operationSmoke === 'save-feed') {
            $handler->saveFeed([], new OperationRequest([
                'collectionCode' => 'AB12345',
                'feedUsername' => 'googlefeed',
                'feedPassword' => 'Secret123',
                'imprintCollectionMap' => '',
                'feedEnabled' => '1',
            ], true));
        } elseif ($operationSmoke === 'save-normalized-code') {
            $handler->saveFeed([], new OperationRequest([
                'collectionCode' => " AB1\u{200B}2345 ",
                'feedUsername' => '',
                'feedPassword' => '',
                'imprintCollectionMap' => '',
            ], true));
        } elseif ($operationSmoke === 'invalid-collection') {
            $handler->saveFeed([], new OperationRequest([
                'collectionCode' => 'AB1234',
                'imprintCollectionMap' => '',
            ], true));
        } elseif ($operationSmoke === 'save-delivery') {
            $handler->saveDelivery([], new OperationRequest([
                'collectionCode' => 'AB12345',
                'imprintCollectionMap' => '',
                'deliveryMode' => 'google_sftp',
                'feedEnabled' => '1',
                'deliverOnixFull' => '1',
                'deliverOnixRights' => '1',
                'deliverEbooks' => '1',
                'deliverValidation' => '',
                'googleSftpHost' => 'sftp.example.test',
                'googleSftpPort' => '22',
                'googleSftpRemoteRoot' => 'publisher/dropbox',
                'googleSftpHostKeyFingerprint' => 'SHA256:testfingerprint',
                'publisherSftpHost' => '',
                'publisherSftpPort' => '22',
                'publisherFtpHost' => '',
                'publisherFtpPort' => '21',
                'publisherFtpPassive' => '1',
                'gcsBucket' => '',
                'gcsPrefix' => '',
                'gcsGoogleReaderServiceAccount' => '',
            ], true));
        } elseif ($operationSmoke === 'save-transport-auth') {
            $handler->saveTransportAuth([], new OperationRequest([
                'googleSftpUsername' => 'google-dropbox-user',
                'googleSftpAuthMode' => 'password',
                'googleSftpPassword' => 'TemporarySftpSecret123',
                'publisherSftpUsername' => '',
                'publisherSftpAuthMode' => 'password',
                'publisherFtpUsername' => '',
                'gcsServiceAccountJson' => '',
            ], true));
        } elseif ($operationSmoke === 'save-behavior') {
            $handler->saveBehavior([], new OperationRequest([
                'autoSync' => '1',
                'autoVerifyGoogle' => '1',
                'defaultFreeOfCharge' => '1',
                'defaultWorldwideRightsForFree' => '',
            ], true));
        } elseif ($operationSmoke === 'discover') {
            $plugin->setGoogleApiKey(1, 'api-key-value');
            $handler->discover([], new OperationRequest([], true));
        } elseif ($operationSmoke === 'sync' || $operationSmoke === 'force') {
            $plugin->updateSetting(1, 'collectionCode', 'AB12345', 'string');
            $plugin->updateSetting(1, 'feedUsername', 'googlefeed', 'string');
            $plugin->updateSetting(1, 'feedPasswordHash', 'hash', 'string');
            $plugin->updateSetting(1, 'feedEnabled', true, 'bool');
            if ($operationSmoke === 'force') {
                $handler->forceRefresh([], new OperationRequest([], true));
            } else {
                $handler->sync([], new OperationRequest([], true));
            }
        } elseif ($operationSmoke === 'csrf') {
            $handler->saveApi([], new OperationRequest([], false));
        } elseif ($operationSmoke === 'download-no-selection') {
            $handler->downloadValidation([], new OperationRequest());
        }
        fwrite(STDERR, "Unknown GOOGLEBOOKS_OPERATION_SMOKE mode\n");
        exit(2);
    }

    $assertions = 0;
    $failures = [];
    $check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
        $assertions++;
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $pluginClass = \APP\plugins\generic\googleBooks\GoogleBooksPlugin::class;
    $plugin = new $pluginClass();
    $check(is_subclass_of($pluginClass, \PKP\plugins\GenericPlugin::class), 'Plugin does not extend PKP GenericPlugin');
    $check($plugin->getName() === 'googlebooksplugin', 'Plugin technical name does not match OMP 3.5 lazy-load identity');
    $check(\APP\plugins\generic\googleBooks\GoogleBooksPlugin::isValidCollectionCode('AB12345'), 'Generic seven-character Partner Center-style collection code AB12345 must be accepted');
    $check(\APP\plugins\generic\googleBooks\GoogleBooksPlugin::normalizeCollectionCode(" AB1\u{200B}2345 ") === 'AB12345', 'Collection code copy/paste whitespace normalization failed');
    $check(!\APP\plugins\generic\googleBooks\GoogleBooksPlugin::isValidCollectionCode('AB1234'), 'Six-character collection code must remain invalid');
    $check($plugin->register('generic', 'plugins/generic/googleBooks', 1), 'Plugin register() returned false');
    // Pre-0.1.2.2 API keys were stored in plaintext. A successful
    // read must migrate the value into the encrypted envelope and clear the
    // legacy setting without changing the key returned to discovery jobs.
    $plugin->updateSetting(1, 'googleApiKey', 'legacy-api-key-value', 'string');
    $check($plugin->hasGoogleApiKey(1), 'Legacy plaintext API key is not recognized during upgrade');
    $check($plugin->getGoogleApiKey(1) === 'legacy-api-key-value', 'Legacy API key could not be recovered during encrypted migration');
    $storedApiKey = (string) $plugin->getSetting(1, 'googleApiKeyEncrypted');
    $check(\APP\plugins\generic\googleBooks\classes\Security\SecretStore::isApiKeyEncrypted($storedApiKey), 'Legacy API key was not migrated into the encrypted envelope');
    $check((string) $plugin->getSetting(1, 'googleApiKey') === '', 'Legacy plaintext API key was not cleared after encrypted migration');
    $plugin->clearGoogleApiKey(1);
    $check(!$plugin->hasGoogleApiKey(1), 'Clearing the stored API key left an API-key setting active');
    $hookNames = array_map(static fn (array $hook): string => $hook[0], \PKP\plugins\Hook::$hooks);
    $check(count($hookNames) === 6, 'Plugin did not register its six OMP hooks');
    $check(
        array_values(array_intersect(
            ['LoadHandler', 'TemplateManager::display', 'Templates::Catalog::Book::Details', 'SubmissionFile::add', 'SubmissionFile::edit', 'SubmissionFile::delete'],
            $hookNames,
        )) === ['LoadHandler', 'TemplateManager::display', 'Templates::Catalog::Book::Details', 'SubmissionFile::add', 'SubmissionFile::edit', 'SubmissionFile::delete'],
        'Plugin OMP hook set is incomplete',
    );
    $detailsHook = null;
    foreach (\PKP\plugins\Hook::$hooks as $registeredHook) {
        if (($registeredHook[0] ?? '') === 'Templates::Catalog::Book::Details') {
            $detailsHook = $registeredHook;
            break;
        }
    }
    $check(
        ($detailsHook[2] ?? null) === \PKP\plugins\Hook::SEQUENCE_CORE,
        'Google Books public identifier must use SEQUENCE_CORE so it renders before the normal-sequence Citation Style Language block',
    );

    $check(count(\Illuminate\Support\Facades\Event::$listeners) === 3, 'Plugin did not register its three OMP event listeners');

    // Reproduce the plugin-grid component router that originally generated
    // /$$$call$$$/goog/fetch-grid. The dashboard action must explicitly ask
    // the dispatcher for a page-route URL instead of calling Request::url().
    $componentRequest = new ComponentRouteRequest();
    $pluginActions = $plugin->getActions($componentRequest, []);
    $dashboardAction = $pluginActions[0] ?? null;
    $redirectAction = $dashboardAction instanceof \PKP\linkAction\LinkAction
        ? ($dashboardAction->arguments[1] ?? null)
        : null;
    $check($dashboardAction instanceof \PKP\linkAction\LinkAction, 'Google Books dashboard plugin action was not created');
    $check($redirectAction instanceof \PKP\linkAction\request\RedirectAction, 'Google Books dashboard action is not a redirect action');
    $check($redirectAction?->url === 'https://press.example/press/pt_BR/googlebooks', 'Google Books dashboard action URL is not a localized explicit page route');
    $check($componentRequest->legacyUrlCalls === 0, 'Google Books dashboard action still calls Request::url() inside the component router');
    $dispatcherCall = $componentRequest->dispatcher->calls[0] ?? [];
    $check(($dispatcherCall[1] ?? null) === \APP\core\Application::ROUTE_PAGE, 'Google Books dashboard action did not request Application::ROUTE_PAGE');
    $check(($dispatcherCall[2] ?? null) === 'press' && ($dispatcherCall[3] ?? null) === 'googlebooks', 'Google Books dashboard page route has the wrong context or page');
    $check(($dispatcherCall[9] ?? null) === null, 'Google Books dashboard action still suppresses the OMP locale segment');
    $check(str_contains($redirectAction?->url ?? '', '/press/pt_BR/googlebooks'), 'Multilingual dashboard URL does not contain the active locale segment');

    $legacyPage = 'googleBooks';
    $legacyOp = 'index';
    $legacySource = 'pages/googleBooks/index.php';
    $legacyHandler = null;
    $legacyDashboardArgs = [&$legacyPage, &$legacyOp, &$legacySource, &$legacyHandler];
    $check($plugin->handlePage('LoadHandler', $legacyDashboardArgs), 'Legacy camel-case dashboard route was not handled');
    $check($legacyPage === 'googlebooks' && $legacyHandler instanceof \APP\plugins\generic\googleBooks\classes\DashboardHandler, 'Camel-case dashboard route was not attached to the dashboard handler');

    $lowerPage = 'googlebooks';
    $lowerOp = 'index';
    $lowerSource = 'pages/googlebooks/index.php';
    $lowerHandler = null;
    $lowerDashboardArgs = [&$lowerPage, &$lowerOp, &$lowerSource, &$lowerHandler];
    $check($plugin->handlePage('LoadHandler', $lowerDashboardArgs), 'Lowercase dashboard alias was not handled');
    $check($lowerPage === 'googlebooks' && $lowerHandler instanceof \APP\plugins\generic\googleBooks\classes\DashboardHandler, 'Lowercase dashboard alias was not canonicalized');

    $feedPage = 'googleBooksFeed';
    $feedOp = 'index';
    $feedSource = 'pages/googleBooksFeed/index.php';
    $feedHandler = null;
    $feedArgs = [&$feedPage, &$feedOp, &$feedSource, &$feedHandler];
    $check($plugin->handlePage('LoadHandler', $feedArgs), 'Legacy feed route was not handled');
    $check($feedPage === 'googlebooksfeed' && $feedHandler instanceof \APP\plugins\generic\googleBooks\classes\Feed\FeedHandler, 'Feed route was not attached to the feed handler');

    $migration = $plugin->getInstallMigration();
    $check($migration instanceof \APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration, 'Plugin install migration type mismatch');
    $migration->up();
    $check(isset(\Illuminate\Support\Facades\Schema::$tables['google_books_records']), 'Records table migration was not declared');
    $check(isset(\Illuminate\Support\Facades\Schema::$tables['google_books_sync_runs']), 'Sync runs table migration was not declared');
    $recordTable = \Illuminate\Support\Facades\Schema::$tables['google_books_records'];
    $check(in_array([['context_id', 'isbn13'], 'google_books_isbn_unique'], $recordTable->uniqueKeys, true), 'Context/ISBN unique migration contract missing');
    $migration->down();
    $check(\Illuminate\Support\Facades\Schema::$tables === [], 'Migration down() did not remove plugin tables');

    // Reproduce an in-place upgrade from a 0.1.0.x schema. The 0.1.1.x
    // migration must add discovery/feed separation columns without requiring
    // the plugin tables to be dropped and recreated.
    \Illuminate\Support\Facades\Schema::create('google_books_records', function ($table): void {
        foreach (['record_id', 'context_id', 'submission_id', 'publication_id', 'isbn13', 'isbn10', 'google_volume_id', 'discovery_status', 'sync_status', 'last_error', 'created_at', 'updated_at'] as $column) {
            $table->string($column);
        }
    });
    \Illuminate\Support\Facades\Schema::create('google_books_sync_runs', function ($table): void {
        foreach (['run_id', 'context_id', 'mode', 'status', 'books_scanned', 'books_linked', 'books_not_found', 'books_updated', 'books_unchanged', 'books_retired', 'books_failed', 'started_at'] as $column) {
            $table->string($column);
        }
    });
    $migration->up();
    $upgradedRecords = \Illuminate\Support\Facades\Schema::$tables['google_books_records'];
    $upgradedRuns = \Illuminate\Support\Facades\Schema::$tables['google_books_sync_runs'];
    foreach (['feed_eligible', 'last_feed_checked_at', 'discovery_error', 'feed_error'] as $column) {
        $check(in_array($column, $upgradedRecords->columns, true), 'Upgrade migration did not add records column ' . $column);
    }
    foreach (['books_skipped', 'books_feed_ineligible'] as $column) {
        $check(in_array($column, $upgradedRuns->columns, true), 'Upgrade migration did not add run column ' . $column);
    }
    $check(\APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration::isCurrent(), 'Schema current-state detector did not recognize upgraded 0.1.0.x tables');
    \APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration::ensureCurrent();
    $check(\APP\plugins\generic\googleBooks\classes\Migration\GoogleBooksSchemaMigration::isCurrent(), 'Idempotent schema ensure failed on current schema');
    $migration->down();

    $dashboard = new \APP\plugins\generic\googleBooks\classes\DashboardHandler($plugin);
    $feed = new \APP\plugins\generic\googleBooks\classes\Feed\FeedHandler($plugin);
    $check($dashboard->_isBackendPage === true && count($dashboard->roleAssignments) === 1, 'Dashboard handler role contract failed');
    $check($feed->restrictedSite === false, 'Feed handler did not disable restricted-site interception before Basic Auth');

    $syncJob = new \APP\plugins\generic\googleBooks\classes\Jobs\SubmissionSyncJob(1, 2, true);
    $catalogJob = new \APP\plugins\generic\googleBooks\classes\Jobs\CatalogSyncJob(1, true, 3);
    $verifyJob = new \APP\plugins\generic\googleBooks\classes\Jobs\CatalogVerifyJob(1, 3);
    $bookVerifyJob = new \APP\plugins\generic\googleBooks\classes\Jobs\BookVerificationJob(1, 2, 1);
    $check($syncJob->contextId === 1 && $syncJob->submissionId === 2 && $syncJob->force, 'Submission queue job constructor contract failed');
    $check($catalogJob->contextId === 1 && $catalogJob->force && $catalogJob->userId === 3, 'Catalog queue job constructor contract failed');
    $check($verifyJob->contextId === 1 && $verifyJob->userId === 3, 'Catalog verification job constructor contract failed');
    $check($bookVerifyJob->contextId === 1 && $bookVerifyJob->submissionId === 2 && $bookVerifyJob->attemptNumber === 1, 'Book verification job constructor contract failed');

    $mapperSource = file_get_contents($root . '/classes/Sync/OmpBookMapper.php');
    $check(str_contains($mapperSource, '->fileExists($path)') && !str_contains($mapperSource, '->has($path)'), 'Explicit OMP 3.5 proof-file existence contract missing');
    $check(str_contains($mapperSource, 'bool $requireContentAssets = true'), 'OMP mapper metadata-only validation mode missing');
    $check(str_contains($mapperSource, "method_exists(\$file, 'getChapterId')") && str_contains($mapperSource, 'if ($chapterId > 0)'), 'OMP 3.5 chapter proof exclusion contract missing');
    $check(str_contains($mapperSource, "['jpg', 'jpeg', 'png']") && str_contains($mapperSource, "'image/png'"), 'OMP 3.5 PNG cover contract missing');
    $validatorSource = file_get_contents($root . '/classes/Onix/GoogleOnixValidator.php');
    $check(str_contains($validatorSource, "['jpg', 'png']") && str_contains($validatorSource, "'image/png'"), 'OMP 3.5 PNG cover validation contract missing');
    $check(str_contains($validatorSource, 'validateMetadataBook') && str_contains($validatorSource, '$errors = $this->validateMetadataBook($book);'), 'OMP 3.5 metadata-only ONIX validation contract missing');
    $dashboardSource = file_get_contents($root . '/classes/DashboardHandler.php');
    $check(str_contains($dashboardSource, 'operationFailure') && substr_count($dashboardSource, 'catch (Throwable $e)') >= 7, 'Dashboard operations are not protected from uncaught plugin exceptions');

    if ($failures !== []) {
        fwrite(STDERR, 'FAILED ' . count($failures) . " of {$assertions} OMP 3.5 smoke assertions\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, ' - ' . $failure . "\n");
        }
        exit(1);
    }

    echo "OK {$assertions} OMP 3.5 smoke assertions\n";
}
