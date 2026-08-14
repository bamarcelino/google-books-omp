#!/usr/bin/env python3
from pathlib import Path
import re
import sys
from lxml import etree

ROOT = Path(__file__).resolve().parents[1]
failures = []
checks = 0

def check(cond, msg):
    global checks
    checks += 1
    if not cond:
        failures.append(msg)

# Required package structure
for rel in [
    'GoogleBooksPlugin.php', 'version.xml', 'upgrade.xml', 'LICENSE', 'README.md', 'VALIDATION.md', 'CHANGELOG.md',
    'classes/Api/GoogleBooksApiClient.php', 'classes/Feed/FeedHandler.php', 'classes/Jobs/CatalogDiscoveryJob.php', 'classes/Jobs/SubmissionDiscoveryJob.php',
    'classes/Delivery/DeliveryConfig.php', 'classes/Delivery/DeliveryManager.php', 'classes/Delivery/DeliveryManifestService.php',
    'classes/Delivery/SftpTransport.php', 'classes/Delivery/FtpTransport.php', 'classes/Delivery/GoogleCloudStorageTransport.php', 'classes/Delivery/LocalExportTransport.php', 'classes/Delivery/TransportCapabilities.php',
    'classes/Jobs/DeliveryJob.php', 'classes/Repository/GoogleBooksDeliveryRepository.php', 'classes/Security/SecretStore.php',
    'classes/Migration/PluginSettingsMigrator.php',
    'classes/Onix/GoogleOnixBuilder.php', 'classes/Sync/GoogleBooksSyncService.php',
    'templates/dashboard.tpl', 'templates/publicIdentifier.tpl', 'styles/dashboard.css', 'scripts/dashboard.js',
    'locale/en/locale.po', 'locale/es/locale.po', 'locale/pt_BR/locale.po',
    'tests/run.php', 'tests/repository_smoke.php', 'tests/mapper_smoke.php',
    'tests/omp35_smoke.php', 'tests/settings_migration_smoke.php', 'tests/package_check.py', 'tests/run_all.sh',
]:
    check((ROOT / rel).is_file(), f'missing required file: {rel}')

# version.xml
parser = etree.XMLParser(load_dtd=False, no_network=True, resolve_entities=False)
version = etree.parse(str(ROOT / 'version.xml'), parser).getroot()
vals = {child.tag: (child.text or '').strip() for child in version if isinstance(child.tag, str)}
check(vals.get('application') == 'googleBooks', 'version.xml application mismatch')
check(vals.get('type') == 'plugins.generic', 'version.xml plugin type mismatch')
check(vals.get('class') == 'GoogleBooksPlugin', 'version.xml class mismatch')
check(vals.get('lazy-load') == '0', '0.1.2.1 must remain non-lazy to repair legacy enabled settings')
check(vals.get('release') == '0.1.2.1', 'version.xml release mismatch')

# OMP plugin-upgrade descriptor contract
upgrade = etree.parse(str(ROOT / 'upgrade.xml'), parser).getroot()
check(upgrade.tag == 'install', 'upgrade.xml root must be <install>')
upgrade_migrations = [el.get('class') for el in upgrade.findall('migration')]
check('APP\\plugins\\generic\\googleBooks\\classes\\Migration\\GoogleBooksSchemaMigration' in upgrade_migrations, 'upgrade.xml does not execute GoogleBooksSchemaMigration')
check(upgrade.get('version') is None, 'plugin upgrade.xml must not replace the application version')

# OMP 3.5 plugin identity and lazy-load repair contract
plugin_source = (ROOT / 'GoogleBooksPlugin.php').read_text(encoding='utf-8')
migrator_source = (ROOT / 'classes/Migration/PluginSettingsMigrator.php').read_text(encoding='utf-8')
check("return self::PLUGIN_NAME;" in plugin_source, 'plugin getName() is not tied to the OMP 3.5 canonical class-name key')
check("CANONICAL_PLUGIN_NAME = 'googlebooksplugin'" in migrator_source, 'canonical OMP 3.5 plugin settings name is wrong')
check("LEGACY_PLUGIN_NAMES = ['googleBooks', 'googlebooks']" in migrator_source, 'legacy 0.1.0.x plugin settings names are not migrated')
check('PluginSettingsMigrator::migrate();' in plugin_source, 'legacy settings migration is not executed before the enabled check')
check('Cache::forget' in migrator_source and 'pluginSettings-{$contextId}-{$pluginName}' in migrator_source, 'direct settings migration does not invalidate OMP plugin-setting caches')
check("DASHBOARD_PAGE = 'googlebooks'" in plugin_source and "FEED_PAGE = 'googlebooksfeed'" in plugin_source, 'canonical lowercase dashboard/feed route constants are missing')
check("strtolower((string) $page)" in plugin_source, 'legacy camel-case route compatibility is missing')

# Reproduce the OMP 3.5 VersionDAO lazy-load gate that caused the 404.
def versiondao_loads(lazy_load, plugin_setting_name, product_class_name='GoogleBooksPlugin'):
    return lazy_load != 1 or plugin_setting_name.lower() == product_class_name.lower()

check(not versiondao_loads(1, 'googleBooks'), 'test fixture no longer reproduces the 0.1.0.1 lazy-load failure')
check(versiondao_loads(0, 'googleBooks'), 'repair descriptor does not bypass the broken legacy lazy-load setting')
check(versiondao_loads(1, 'googlebooksplugin'), 'migrated canonical setting would not pass OMP 3.5 lazy loading')

# Locale consistency / duplicate keys
locale_sets = {}
for locale in ['en', 'es', 'pt_BR']:
    text = (ROOT / 'locale' / locale / 'locale.po').read_text(encoding='utf-8')
    keys = re.findall(r'^msgid\s+"([^"]+)"\s*$', text, flags=re.M)
    check(len(keys) == len(set(keys)), f'duplicate locale msgid in {locale}')
    check('msgid ""\nmsgstr ""' in text, f'gettext header missing in {locale}')
    check(not re.search(r'^msgstr\s+".*"\nmsgid\s+', text, flags=re.M), f'PO entries are not separated in {locale}')
    locale_sets[locale] = set(keys)
check(locale_sets['en'] == locale_sets['es'] == locale_sets['pt_BR'], 'locale key sets differ')
template_text = '\n'.join(p.read_text(encoding='utf-8') for p in (ROOT / 'templates').glob('*.tpl'))
template_keys = set(re.findall(r'translate\s+key="([^"]+)"', template_text))
plugin_template_keys = {k for k in template_keys if k.startswith('plugins.generic.googleBooks.')}
for locale, keys in locale_sets.items():
    missing = sorted(plugin_template_keys - keys)
    check(not missing, f'missing template locale keys in {locale}: {missing[:8]}')
source_translation_keys = set()
for path in [ROOT / 'GoogleBooksPlugin.php', *(ROOT / 'classes').rglob('*.php'), *(ROOT / 'templates').rglob('*.tpl'), *(ROOT / 'scripts').rglob('*.js')]:
    source_translation_keys.update(re.findall(r'plugins\.generic\.googleBooks\.[A-Za-z0-9_.-]+', path.read_text(encoding='utf-8')))
for locale, keys in locale_sets.items():
    missing = sorted(source_translation_keys - keys)
    check(not missing, f'missing plugin source locale keys in {locale}: {missing[:8]}')

# Generated Google ONIX contract
onix_path = ROOT / 'tests' / 'generated-onix.xml'
check(onix_path.is_file(), 'generated ONIX test artifact missing')
if onix_path.is_file():
    doc = etree.parse(str(onix_path), parser)
    root = doc.getroot()
    ns = {'o': 'http://ns.editeur.org/onix/3.0/reference'}
    check(root.tag == '{http://ns.editeur.org/onix/3.0/reference}ONIXMessage', 'ONIX namespace/root mismatch')
    check(root.get('release') == '3.0', 'ONIX release attribute mismatch')
    products = doc.xpath('/o:ONIXMessage/o:Product', namespaces=ns)
    check(len(products) >= 1, 'ONIX contains no Product')
    empties = [el.tag for el in root.iter() if len(el) == 0 and (el.text is None or not el.text.strip())]
    check(not empties, f'ONIX contains empty elements: {empties[:5]}')
    for product in products:
        refs = product.xpath('./o:RecordReference/text()', namespaces=ns)
        check(bool(refs) and bool(re.fullmatch(r'\d{13}', refs[0])), 'RecordReference is not canonical ISBN-13')
        pid_type = product.xpath('./o:ProductIdentifier/o:ProductIDType/text()', namespaces=ns)
        pid_val = product.xpath('./o:ProductIdentifier/o:IDValue/text()', namespaces=ns)
        check('15' in pid_type and refs[0] in pid_val, 'ISBN-13 ProductIdentifier mismatch')
        for supply in product.xpath('./o:ProductSupply/o:SupplyDetail', namespaces=ns):
            free = supply.xpath('./o:UnpricedItemType/text()', namespaces=ns)
            if free:
                check(free == ['01'], 'free SupplyDetail must contain exactly UnpricedItemType 01')
                check(not supply.xpath('./o:Price', namespaces=ns), 'free SupplyDetail must not contain Price')
        for rights in product.xpath('./o:PublishingDetail/o:SalesRights', namespaces=ns):
            check(bool(rights.xpath('./o:Territory', namespaces=ns)), 'SalesRights missing Territory')
    collections = doc.xpath('//o:Collection', namespaces=ns)
    for collection in collections:
        identifiers = collection.xpath('./o:CollectionIdentifier', namespaces=ns)
        check(bool(identifiers), 'Collection is missing CollectionIdentifier')
    issns = doc.xpath('//o:CollectionIdentifier[o:CollectionIDType="02"]/o:IDValue/text()', namespaces=ns)
    for issn in issns:
        check(bool(re.fullmatch(r'[0-9]{7}[0-9X]', issn)), f'collection ISSN is not canonical: {issn}')
    proprietary = doc.xpath('//o:CollectionIdentifier[o:CollectionIDType="01"]/o:IDValue/text()', namespaces=ns)
    for identifier in proprietary:
        check(bool(re.fullmatch(r'[A-Z0-9._:-]{1,100}', identifier)), f'proprietary collection identifier is invalid: {identifier}')

# Migration uniqueness / publisher neutral runtime
migration = (ROOT / 'classes/Migration/GoogleBooksSchemaMigration.php').read_text(encoding='utf-8')
check("$table->unique(['context_id', 'isbn13']" in migration, 'canonical context/ISBN unique constraint missing')
check('public static function ensureCurrent(): void' in migration and 'public static function isCurrent(): bool' in migration, 'idempotent schema repair helpers missing')
handler = (ROOT / 'classes/DashboardHandler.php').read_text(encoding='utf-8')
check('GoogleBooksSchemaMigration::ensureCurrent();' in handler, 'dashboard does not repair missed upgrade schema before repository queries')
mapper = (ROOT / 'classes/Sync/OmpBookMapper.php').read_text(encoding='utf-8')
check("DAORegistry::getDAO('PublicationFormatDAO')" in mapper and 'getByPublicationId' in mapper, 'OMP 3.5 PublicationFormatDAO mapping contract missing')
check("'24' => 30" in mapper and 'getFormatDiscoveryIsbns13' in mapper, 'co-publisher ISBN-13 discovery contract missing')
check('backgroundQueueConnection' in handler and "->onConnection($this->backgroundQueueConnection())" in handler and "modify('+5 seconds')" in handler, 'manual dashboard jobs are not isolated from synchronous queue/request execution')

runtime_php = '\n'.join(p.read_text(encoding='utf-8') for p in ROOT.rglob('*.php') if 'tests' not in p.parts)
check('AB12345' not in runtime_php, 'runtime source contains a hardcoded collection code')
check(not re.search(r"['\"](?:EUR|USD|GBP|CAD)['\"]", runtime_php), 'runtime source contains a hardcoded currency')
check('press.scientia' not in runtime_php.lower(), 'runtime source contains a Scientia deployment domain')
check('scientia.international/' not in runtime_php.lower(), 'runtime source contains a Scientia deployment URL')
check('Bruno Cesar Alves Marcelino' in runtime_php, 'author attribution missing')
check('Scientia International' in runtime_php, 'organization attribution missing')

# Security / operational source contracts
feed = (ROOT / 'classes/Feed/FeedHandler.php').read_text(encoding='utf-8')
check('BasicAuth::diagnostic' in feed and 'BasicAuth::challenge' in feed and 'storeAuthDiagnostic' in feed, 'feed authentication/diagnostic contract missing')
check('X-Robots-Tag: noindex, nofollow, noarchive' in feed, 'feed noindex header missing')
check("HTTP_IF_MODIFIED_SINCE" in feed and '304 Not Modified' in feed, 'conditional feed retrieval support missing')

api = (ROOT / 'classes/Api/GoogleBooksApiClient.php').read_text(encoding='utf-8')
check("'q' => 'isbn:' . $isbn" in api, 'Google Books exact ISBN query missing')
check('IdentifierNormalizer::isbnEquivalents' in api, 'Google Books identifier normalization missing')
check('ambiguous: true' in api, 'multiple exact Google Volume detection missing')
check('RequestException' in api and "'HTTP ' . $last->getResponse()->getStatusCode()" in api, 'Google API error sanitization contract missing')

repository = (ROOT / 'classes/Repository/GoogleBooksStateRepository.php').read_text(encoding='utf-8')
check('if ($match->found)' in repository and 'Never erase a previously linked Google ID' in repository, 'previous Google Volume linkage preservation contract missing')
check('retireMissingProducts' in repository and "'sync_status' => 'retired'" in repository, 'stale ISBN retirement contract missing')
check('retireMissingSubmissions' in repository, 'unpublished catalog reconciliation contract missing')
check('max(time(), $previousEpoch + 1)' in repository, 'monotonic forced asset timestamp contract missing')

sync = (ROOT / 'classes/Sync/GoogleBooksSyncService.php').read_text(encoding='utf-8')
check('public function discoverSubmission' in sync and 'public function syncSubmission' in sync and sync.count('new GoogleBooksApiClient') == 1, 'feed/API independence contract missing')
check('no valid Google collection code is configured for this book/imprint' in sync, 'per-book collection routing validation missing')
check('retireMissingProducts' in sync and "'feedChanged'" in sync, 'submission feed-state reconciliation contract missing')
check('retireMissingSubmissions' in sync, 'full catalog retirement reconciliation contract missing')
check(sync.count("$result['retryable']++") >= 2 and 'markDiscoveryError' in sync, 'retryable Google discovery/verification contract missing')

plugin = (ROOT / 'GoogleBooksPlugin.php').read_text(encoding='utf-8')
check('PublicationUnpublished::class' in plugin and 'retireSubmission' in plugin, 'OMP unpublish retirement listener missing')
jobs_source = '\n'.join(p.read_text(encoding='utf-8') for p in (ROOT / 'classes/Jobs').glob('*.php'))
check("PluginRegistry::getPlugin('generic', GoogleBooksPlugin::PLUGIN_NAME)" in jobs_source, 'queue jobs look up the plugin under the wrong registry key')
check("PluginRegistry::loadPlugin('generic', GoogleBooksPlugin::PRODUCT_NAME" in jobs_source, 'queue jobs no longer load the plugin by its installation directory')
check('max(time(), $current + 1)' in plugin, 'monotonic catalog feed revision contract missing')
check(all(hook in plugin for hook in ["SubmissionFile::add", "SubmissionFile::edit", "SubmissionFile::delete"]), 'published proof-file synchronization hooks missing')
check('SubmissionFile::SUBMISSION_FILE_PROOF' in plugin and 'Application::ASSOC_TYPE_PUBLICATION_FORMAT' in plugin, 'proof-file hook filtering contract missing')
check(plugin.count('SubmissionSyncJob::dispatchAfterResponse') >= 3 and plugin.count('SubmissionDiscoveryJob::dispatchAfterResponse') >= 2, 'automatic OMP synchronization/discovery is not deferred until committed request state')
check('SubmissionFile::edit is emitted before OMP persists' in plugin, 'proof-file pre-persistence hook safeguard is undocumented')

dashboard = (ROOT / 'classes/DashboardHandler.php').read_text(encoding='utf-8')
check('apiKeyRequired' in dashboard, 'explicit Google verification API-key gate missing')
check('https://books.google.com/books?id=' in dashboard, 'dashboard Google Books page fallback is missing')
check('operationFailure' in dashboard and dashboard.count('catch (Throwable $e)') >= 7, 'dashboard operations may still expose uncaught HTTP 500 errors')
check('csrfExpired' in dashboard and 'if (!$request->checkCSRF())' in dashboard, 'dashboard CSRF failure is not converted to a controlled redirect')
check(all(name in dashboard for name in ["'googleBooksSaveApiUrl'", "'googleBooksSaveFeedUrl'", "'googleBooksSaveBehaviorUrl'", "'googleBooksDiscoverUrl'", "'googleBooksDownloadValidationUrl'", "'googleBooksSetValidationUrl'"]), 'dashboard does not assign explicit page-route URLs')
check('private function feedPageUrl' in dashboard and '$context?->getPrimaryLocale()' in dashboard, 'dashboard feed/validation URLs are not pinned to the press primary locale')
check('urlLocaleForPage as null' in dashboard or 'false,\n            null,' in dashboard, 'dashboard action URLs do not preserve the active OMP locale')

check('feedAuthDiagnostic' in dashboard and "'googleBooksFeedAuthDiagnostic'" in dashboard, 'dashboard feed authentication diagnostic wiring missing')
check("updateSetting($contextId, 'feedAuthDiagnostic', '', 'string')" in dashboard, 'saving feed settings does not clear stale authentication diagnostics')
auth_source = (ROOT / 'classes/Feed/BasicAuth.php').read_text(encoding='utf-8')
check('FCGI_HTTP_AUTHORIZATION' in auth_source and "getenv($key)" in auth_source and "'env:' . $key" in auth_source, 'FastCGI/environment Authorization fallback missing')
check("'usernameMatches'" in auth_source and "'passwordMatches'" in auth_source and "'authorizationPresent'" in auth_source, 'secret-free authentication diagnostic fields missing')
check("'user' =>" not in '\n'.join(line for line in auth_source.splitlines() if 'return [' in line), 'authentication diagnostic package check unexpectedly exposed a user field')
template_source = (ROOT / 'templates/dashboard.tpl').read_text(encoding='utf-8')
check('googleBooksFeedAuthDiagnostic' in template_source and 'authPasswordMatches' in template_source, 'authentication diagnostic is not rendered in the dashboard')

mapper = (ROOT / 'classes/Sync/OmpBookMapper.php').read_text(encoding='utf-8')
check("'onlineIssn', 'printIssn'" in mapper and 'IdentifierNormalizer::normalizeIssn' in mapper, 'OMP series ISSN normalization contract missing')
check("'OMP' . $contextId . 'S' . (int) $seriesId" in mapper, 'stable OMP series identifier fallback missing')
check("method_exists($file, 'getChapterId')" in mapper and 'if ($chapterId > 0)' in mapper, 'whole-book mapping does not exclude chapter proof files')
check("['jpg', 'jpeg', 'png']" in mapper and "'image/png'" in mapper, 'PNG cover support contract missing')
validator_source = (ROOT / 'classes/Onix/GoogleOnixValidator.php').read_text(encoding='utf-8')
check("['jpg', 'png']" in validator_source and "'image/png'" in validator_source, 'PNG cover validation contract missing')
check('public function validateMetadataBook' in validator_source, 'metadata-only ONIX validator is missing')
check('public function validateBook' in validator_source and '$errors = $this->validateMetadataBook($book);' in validator_source, 'live feed validator does not extend metadata validation')
check("localizedData($publication, 'title', $language)" in mapper, 'publication-locale metadata mapping contract missing')
check('->fileExists($path)' in mapper and '->has($path)' not in mapper, 'Explicit OMP 3.5 proof-file existence check is missing')
check('bool $requireContentAssets = true' in mapper, 'metadata-only ONIX validation mapper mode is missing')
check('public function mapDiscoverySubmission' in mapper and 'independent from feed eligibility' in mapper, 'API-only historical catalogue mapper is missing')

migration = (ROOT / 'classes/Migration/GoogleBooksSchemaMigration.php').read_text(encoding='utf-8')
check('books_retired' in migration, 'sync-run retired product counter missing')
check("method_exists($format, 'getIsAvailable')" in mapper, 'unavailable OMP publication formats are not excluded')
check('$directSalesPrice === null' in mapper, 'non-public OMP proof files are not excluded')

manifest = (ROOT / 'classes/Feed/FeedManifestService.php').read_text(encoding='utf-8')
check("new DateTimeImmutable('@' . $revision)" in manifest, 'ONIX SentDateTime is not tied to feed revision')
check("new DateTimeImmutable('now'" not in manifest, 'feed XML is not deterministic within one revision')
check('validateMetadataBook($book)' in manifest and 'validateRightsBook($book)' in manifest, 'validation sample and live rights feed validation are not separated')
check('mapSubmission(' in manifest and 'false,' in manifest, 'initial ONIX validation sample does not permit metadata-only mapping')

plugin = (ROOT / 'GoogleBooksPlugin.php').read_text(encoding='utf-8')
check('max(time(), $current + 1)' in plugin, 'feed revision is not monotonic')
check("(string) ($record->sync_status ?? '') === 'retired'" in plugin, 'public link does not exclude retired products')
check("(string) $record->sync_status !== 'feed_available'" not in plugin, 'exact existing Google records are hidden until publisher-feed synchronization')
check("(string) ($record->discovery_status ?? '') === 'multiple_matches'" in plugin, 'public link does not withhold ambiguous Google matches')
check('https://books.google.com/books?id=' in plugin, 'public Google Books page fallback is missing')
check('Application::ROUTE_PAGE' in plugin and '$request->getDispatcher()->url(' in plugin, 'dashboard action does not use an explicit OMP page route')
check("urlLocaleForPage: ''" not in plugin, 'dashboard action still suppresses locale insertion and can lose POST bodies on multilingual OMP')
dashboard_tpl = (ROOT / 'templates/dashboard.tpl').read_text(encoding='utf-8')
dashboard_css = (ROOT / 'styles/dashboard.css').read_text(encoding='utf-8')
check('pkpButton' not in dashboard_tpl and 'pkp_button' in dashboard_tpl and 'pkp_button_primary' in dashboard_tpl, 'dashboard buttons are not using the native OMP 3.5 button classes')
check('.gb-dashboard' in dashboard_css and '.gb-stats' in dashboard_css and '.gb-form-grid' in dashboard_css, 'scoped Google Books dashboard stylesheet is incomplete')
check('addStyleSheet' in dashboard and "styles/dashboard.css" in dashboard and 'addJavaScript' in dashboard and 'scripts/dashboard.js' in dashboard, 'dashboard assets are not registered through TemplateManager')
check('normalizeCollectionCode' in dashboard and 'collectionCodeAttempt' in dashboard, 'collection-code copy/paste normalization or invalid-attempt feedback is missing')
check('maxlength="32"' in dashboard_tpl and 'pattern="[A-Za-z0-9]{7}"' not in dashboard_tpl, 'browser-side collection-code constraints can still truncate or block copy/paste normalization')
dashboard_js = (ROOT / 'scripts/dashboard.js').read_text(encoding='utf-8')
check('gb-generate-credentials' in dashboard_tpl and 'crypto.getRandomValues' in dashboard_js, 'publisher-side feed credential generator missing')
check('Math.random' not in dashboard_js and 'value >= 248' in dashboard_js, 'feed credential generator is not using unbiased cryptographic randomness')
check('{url page="googlebooks"' not in dashboard_tpl, 'dashboard still builds form actions relative to the current request/router')
check(all(name in dashboard_tpl for name in ['googleBooksSaveApiUrl', 'googleBooksSaveCrawlerAuthUrl', 'googleBooksSaveTransportAuthUrl', 'googleBooksSaveDeliveryUrl', 'googleBooksTestDeliveryUrl', 'googleBooksDeliverNowUrl', 'googleBooksSaveBehaviorUrl', 'googleBooksDiscoverUrl', 'googleBooksSyncUrl', 'googleBooksForceRefreshUrl', 'googleBooksSetValidationUrl', 'googleBooksDownloadValidationUrl', 'googleBooksDiscoverBookUrl', 'googleBooksSyncBookUrl']), 'dashboard explicit controller-generated action URLs are incomplete')
check("$request->url(null, 'googleBooksFeed'" not in feed, 'feed links still use request-relative URL generation')
check('GoogleBooksPlugin::FEED_PAGE' in feed and "Application::ROUTE_PAGE" in feed, 'feed links do not use explicit page routes')
check("$context?->getPrimaryLocale()" in feed, 'feed URLs do not pin the press primary locale')
check("$request->url(null, 'googleBooks')" not in plugin, 'dashboard action still leaks the component-router placeholder through Request::url()')

delivery_config = (ROOT / 'classes/Delivery/DeliveryConfig.php').read_text(encoding='utf-8')
delivery_manager = (ROOT / 'classes/Delivery/DeliveryManager.php').read_text(encoding='utf-8')
delivery_manifest = (ROOT / 'classes/Delivery/DeliveryManifestService.php').read_text(encoding='utf-8')
secret_store = (ROOT / 'classes/Security/SecretStore.php').read_text(encoding='utf-8')
delivery_migration = (ROOT / 'classes/Migration/GoogleBooksSchemaMigration.php').read_text(encoding='utf-8')
check(all(mode in delivery_config for mode in ["HTTP_PULL = 'http_pull'", "GOOGLE_SFTP = 'google_sftp'", "PUBLISHER_SFTP = 'publisher_sftp'", "PUBLISHER_FTP = 'publisher_ftp'", "GCS = 'gcs'", "LOCAL_EXPORT = 'local_export'"]), 'complete delivery transport registry missing')
check("aes-256-gcm" in secret_store and "general', 'app_key'" in secret_store and 'Authorization' not in secret_store, 'outbound transport secret encryption contract is incomplete')
check('google_books_delivery_files' in delivery_migration and "path_hash" in delivery_migration and "transport_key" in delivery_migration, 'incremental delivery state migration missing')
check("DeliveryConfig::GOOGLE_SFTP" in delivery_manager and "DeliveryConfig::PUBLISHER_SFTP" in delivery_manager and "DeliveryConfig::PUBLISHER_FTP" in delivery_manager and "DeliveryConfig::GCS" in delivery_manager and "DeliveryConfig::LOCAL_EXPORT" in delivery_manager, 'delivery manager does not route every supported push/staging transport')
check("'onix/' . $code . '-full/'" in delivery_manifest and "'onix/' . $code . '-rights/'" in delivery_manifest and "'ebooks/' . $code . '/'" in delivery_manifest, 'delivery tree does not follow Google automated-fetch directories')
check('data-gb-tab="authentication"' in dashboard_tpl and 'data-gb-tab="delivery"' in dashboard_tpl and 'data-gb-delivery-mode' in dashboard_tpl, 'dashboard authentication/delivery tab architecture missing')
check('sessionStorage' in dashboard_js and 'dataset.gbTransport' in dashboard_js, 'dashboard tab persistence or transport-panel switching missing')
check('?v=' in dashboard and 'googleBooksDashboardJsUrl' in dashboard and 'googleBooksDashboardJsUrl' in dashboard_tpl, 'dashboard assets are not cache-busted and directly loadable')
check('data-gb-tab-radio="overview"' in dashboard_tpl and '#gb-tab-authentication:checked' in dashboard_css and '#gb-tab-catalog:checked' in dashboard_css, 'no-JavaScript dashboard tab fallback missing')
check("document.readyState === 'loading'" in dashboard_js and '__googleBooksDashboardScriptRegistered' in dashboard_js, 'dashboard JavaScript bootstrap is not late-load safe/idempotent')
check('type="text/javascript"></script>' in dashboard_tpl and 'googleBooksDashboardJsUrl' in dashboard_tpl, 'direct dashboard JavaScript fallback missing')
check("deliveryMode == 'google_sftp'" in dashboard_tpl and "deliveryMode == 'http_pull'" in dashboard_tpl, 'saved delivery transport is not server-rendered active')
check("SecretStore::encrypt" in dashboard and "gcsServiceAccountEncrypted" in dashboard and "PrivateKeyEncrypted" in dashboard, 'dashboard does not encrypt reversible transport credentials')
check('deliveryConnectionDiagnostic' in dashboard and 'deliveryDiagnostic' in dashboard, 'delivery diagnostics are not wired to the dashboard')
check('DeliveryJob::dispatch' in (ROOT / 'classes/Jobs/CatalogSyncJob.php').read_text(encoding='utf-8') and 'DeliveryJob::dispatch' in (ROOT / 'classes/Jobs/SubmissionSyncJob.php').read_text(encoding='utf-8'), 'non-HTTP transport delivery is not queued after feed changes')

builder = (ROOT / 'classes/Onix/GoogleOnixBuilder.php').read_text(encoding='utf-8')
check('$this->buildTerritory($market, 5)' in builder, 'paid Price territory is missing from Google ONIX profile')
check('contributorRoles($contributor)' in builder, 'multiple ONIX contributor-role support missing')
check("CollectionIDType', '01'" in builder and "IDTypeName', 'Publisher Series ID'" in builder, 'proprietary ONIX collection identifier fallback missing')
check("Google Books requires at least one A01 contributor role" in (ROOT / 'classes/Onix/GoogleOnixValidator.php').read_text(encoding='utf-8'), 'Google A01 contributor requirement missing')
check("$contributors[0]['roles'][] = 'A01'" in mapper, 'editor-only OMP compatibility role mapping missing')

job_files = [f for f in (ROOT / 'classes/Jobs').glob('*.php') if f.name not in {'CatalogVerifyJob.php', 'GoogleBooksJob.php'}]
check(bool(job_files) and all('getEnabled($this->contextId)' in f.read_text(encoding='utf-8') for f in job_files), 'active queued jobs do not stop cleanly when the plugin is disabled')
# OMP 3.5.0-5 BaseJob declares `public $tries = 3` without a PHP property type.
# PHP property types are invariant, so a child `public int $tries` causes a fatal
# class-declaration error before the dashboard handler can catch it.
job_runtime_sources = {f.name: f.read_text(encoding='utf-8') for f in (ROOT / 'classes/Jobs').glob('*.php')}
check(not any(re.search(r'public\s+int\s+\$tries\b', src) for src in job_runtime_sources.values()), 'job class redeclares OMP BaseJob::$tries with an incompatible int property type')
check(all(('public $tries' in src) or ('$tries' not in src) for name, src in job_runtime_sources.items()), 'job retry declarations are not OMP 3.5.0-5 compatible')

queue_base = (ROOT / 'classes/Jobs/GoogleBooksJob.php').read_text(encoding='utf-8')
check("strtolower($connection) === 'sync'" in queue_base and "$this->connection = 'database'" in queue_base, 'Google Books jobs do not force synchronous queue work into the background database queue')
check("onConnection((string) ($this->connection ?: 'database'))" in (ROOT / 'classes/Jobs/CatalogDiscoveryJob.php').read_text(encoding='utf-8'), 'catalogue continuation does not preserve its safe background queue connection')
submission_job = (ROOT / 'classes/Jobs/SubmissionSyncJob.php').read_text(encoding='utf-8')
check("getSetting($this->contextId, 'googleApiKey')" in submission_job, 'per-book post-crawl verification is scheduled without a Google API key')
check('BookVerificationJob::dispatch' in submission_job and 'GoogleBooksApiClient' not in submission_job, 'per-book post-crawl discovery scheduling contract missing')
catalog_sync_job = (ROOT / 'classes/Jobs/CatalogSyncJob.php').read_text(encoding='utf-8')
book_verification_job = (ROOT / 'classes/Jobs/BookVerificationJob.php').read_text(encoding='utf-8')
catalog_verification_job = (ROOT / 'classes/Jobs/CatalogVerifyJob.php').read_text(encoding='utf-8')
check('CatalogDiscoveryJob::dispatch' in catalog_sync_job and 'GoogleBooksApiClient' not in catalog_sync_job, 'catalog post-crawl discovery scheduling contract missing')
check("$result['retryable'] === 0" in book_verification_job and 'CatalogDiscoveryJob::dispatch' in catalog_verification_job, 'bounded/compatibility discovery retry contract missing')

if failures:
    print(f'FAILED {len(failures)} of {checks} package checks')
    for failure in failures:
        print(' -', failure)
    sys.exit(1)
print(f'OK {checks} package checks')
