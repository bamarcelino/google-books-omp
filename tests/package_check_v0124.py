#!/usr/bin/env python3
from pathlib import Path

# Keep the long-standing package contract test readable while applying the
# maintenance-release version markers in one place. This wrapper executes the
# previous contract suite and layers the live Google onboarding regressions on
# top without duplicating the whole package checker.
ROOT = Path(__file__).resolve().parents[1]
source_path = ROOT / 'tests' / 'package_check.py'
source = source_path.read_text(encoding='utf-8')

# Historical substitutions are intentionally retained because older source
# packages can still be inspected with this maintenance wrapper.
source = source.replace(
    "check(vals.get('lazy-load') == '0', '0.1.2.3 must remain non-lazy to repair legacy enabled settings')",
    "check(vals.get('lazy-load') == '0', '0.1.2.14 must remain non-lazy to repair legacy enabled settings')",
)
source = source.replace(
    "check(vals.get('release') == '0.1.2.3', 'version.xml release mismatch')",
    "check(vals.get('release') == '0.1.2.14', 'version.xml release mismatch')",
)
source = source.replace('GoogleBooksIntegrationForOMP/0.1.2.3', 'GoogleBooksIntegrationForOMP/0.1.2.14')
source = source.replace(
    "check(vals.get('lazy-load') == '0', '0.1.2.5 must remain non-lazy to repair legacy enabled settings')",
    "check(vals.get('lazy-load') == '0', '0.1.2.14 must remain non-lazy to repair legacy enabled settings')",
)
source = source.replace(
    "check(vals.get('release') == '0.1.2.5', 'version.xml release mismatch')",
    "check(vals.get('release') == '0.1.2.14', 'version.xml release mismatch')",
)
source = source.replace('GoogleBooksIntegrationForOMP/0.1.2.5', 'GoogleBooksIntegrationForOMP/0.1.2.14')

extra = r'''
# 0.1.2.4+ live-onboarding regressions
check("return (bool) $this->plugin->getSetting($contextId, 'feedEnabled');" in feed,
      'HTTP feed is not controlled independently by feedEnabled')
check('DeliveryConfig::mode(' not in feed,
      'HTTP feed is incorrectly coupled to the selected push/staging delivery mode')
feed_manifest = (ROOT / 'classes/Feed/FeedManifestService.php').read_text(encoding='utf-8')
check('VALIDATION_TARGET_COUNT = 10' in feed_manifest,
      'validation sample target is not 10 real products')
check('filterByStatus([Submission::STATUS_PUBLISHED])' in feed_manifest
      and 'validateCommercialMetadataBook' in feed_manifest,
      'validation sample does not supplement from real published commercially valid OMP products')
check('new BookMetadata(' not in feed_manifest,
      'validation sample fabricates synthetic BookMetadata products')
check('array_values($books)' in feed_manifest,
      'validation sample does not emit the collected real product set')

# 0.1.2.6 final-verification regressions
check("VALIDATION_FILENAME = 'googlebooksvalidation.xml'" in feed,
      'validation sample does not expose one permanent canonical filename')
check('googlebooksvalidation[0-9]+' in feed and 'isValidationFilename' in feed,
      'legacy validation URLs are not retained as compatibility aliases')
check('Refusing to deliver incomplete Google Books ONIX XML.' in feed,
      'HTTP boundary does not reject incomplete ONIX before output')
check("header('Content-Length: ' . strlen($xml))" in feed,
      'ONIX HTTP response does not advertise its exact byte length')
check('no-transform' in feed and 'zlib.output_compression' in feed,
      'ONIX response does not guard against intermediary/PHP transformations')
check('assertDeliverableXml' in feed_manifest and 'validateXml($xml)' in feed_manifest,
      'generated ONIX is not structurally validated before HTTP delivery')
check("substr_count($xml, '<Product>')" in feed_manifest
      and "substr_count($xml, '</Product>')" in feed_manifest,
      'pre-delivery ONIX validation does not verify balanced product counts')
large_feed_test = (ROOT / 'tests' / 'onix_large_feed_smoke.php').read_text(encoding='utf-8')
check('<= 150' in large_feed_test and "substr_count($xml, '<Product>') === 150" in large_feed_test,
      '150-product regression fixture is missing')
check("<UnpricedItemType>01</UnpricedItemType>') === 150" in large_feed_test
      and "substr_count($xml, '<Price>') === 0" in large_feed_test,
      'free-of-charge Google Play Books regression is missing')
language_mapper = (ROOT / 'classes/Util/LanguageMapper.php').read_text(encoding='utf-8')
check("'de' => 'deu'" in language_mapper and "'fr' => 'fra'" in language_mapper
      and "'nl' => 'nld'" in language_mapper and "'gn' => 'grn'" in language_mapper,
      'canonical ONIX ISO 639-2 language mappings are missing')

# 0.1.2.9 Google commercial-validation regressions
validator = (ROOT / 'classes/Onix/GoogleOnixValidator.php').read_text(encoding='utf-8')
check('validateCommercialMetadataBook' in validator and 'validateCommercialTerms' in validator,
      'Google validation sample does not validate commercial metadata without requiring content assets')
check('validateCommercialXml' in validator and 'missing ProductSupply' in validator and 'missing SalesRights' in validator,
      'Google commercial XML profile validator is missing')
check("$this->validator->validateCommercialXml($xml)" in feed_manifest,
      'commercial ONIX is not profile-validated before delivery')
check("'validation-commercial'" in feed_manifest and 'self::VALIDATION_TARGET_COUNT' in feed_manifest,
      'validation sample does not enforce exactly ten commercially complete products')
check("$this->sentAt($contextId),\n            true," in feed_manifest,
      'validation sample is still built without SalesRights/ProductSupply')
commercial_test_path = ROOT / 'tests' / 'onix_validation_commercial_smoke.php'
check(commercial_test_path.is_file(), 'commercial validation regression test is missing')
if commercial_test_path.is_file():
    commercial_test = commercial_test_path.read_text(encoding='utf-8')
    check("substr_count($xml, '<SalesRights>') === 10" in commercial_test,
          'ten-record validation test does not require SalesRights on every Product')
    check("substr_count($xml, '<ProductSupply>') === 10" in commercial_test,
          'ten-record validation test does not require ProductSupply on every Product')
    check("substr_count($xml, '<UnpricedItemType>01</UnpricedItemType>') === 10" in commercial_test,
          'free validation products are not tested as UnpricedItemType 01')


# 0.1.2.9 source-backed ONIX enrichment regressions
book_metadata = (ROOT / 'classes/Model/BookMetadata.php').read_text(encoding='utf-8')
builder = (ROOT / 'classes/Onix/GoogleOnixBuilder.php').read_text(encoding='utf-8')
enrichment = (ROOT / 'classes/Onix/OnixEnrichmentService.php').read_text(encoding='utf-8')
check('public array $subjects = []' in book_metadata and 'public array $extents = []' in book_metadata and 'public array $relatedProducts = []' in book_metadata,
      'BookMetadata does not carry optional ONIX enrichment fields')
check('<Subject>' in builder and 'SubjectSchemeIdentifier' in builder and 'SubjectHeadingText' in builder,
      'ONIX builder does not emit Subject composites')
check('<Extent>' in builder and 'ExtentType' in builder and 'ExtentValue' in builder and 'ExtentUnit' in builder,
      'ONIX builder does not emit Extent composites')
check('<RelatedMaterial>' in builder and 'ProductRelationCode' in builder,
      'ONIX builder does not emit RelatedProduct composites')
check("['bisac', 'bisacCode', 'bisacCodes']" in enrichment and "'scheme' => '10'" in enrichment,
      'explicit publisher BISAC fields are not recognized')
check("'scheme' => '20'" not in enrichment and "['keywords', 'subjects', 'disciplines']" not in enrichment and "['thema', 'themaCode', 'themaCodes']" not in enrichment,
      'unsupported/free-text Google subject export is still present')
check("'relationCode' => '06'" in enrichment and 'canonicalFormatIsbn' in enrichment,
      'related edition ISBNs are not derived from canonical OMP publication formats')
check('positiveIntegerFromFormat' in enrichment and "'frontMatter'" in enrichment and "'backMatter'" in enrichment,
      'OMP page/extents metadata is not mapped without guessing')
check('guessSubject' not in enrichment and 'guessPage' not in enrichment,
      'ONIX enrichment introduced synthetic metadata inference')
check('defaultBisacCode' in enrichment and "'scheme' => '10'" in enrichment,
      'validated manager-configured fallback BISAC support is missing')
check("EditionType', 'DGO'" in builder and '$book->relatedProducts === []' in builder,
      'digital-only products do not declare ONIX EditionType DGO')
check("BiographicalNote', $contributor['biography']" in builder and 'contributorBiography' in mapper,
      'OMP contributor biographies are not mapped to ONIX BiographicalNote')
dashboard_handler = (ROOT / 'classes/DashboardHandler.php').read_text(encoding='utf-8')
dashboard_template = (ROOT / 'templates/dashboard.tpl').read_text(encoding='utf-8')
check('defaultBisacCode' in dashboard_handler and 'defaultBisacCode' in dashboard_template,
      'default BISAC management setting is not exposed and persisted')
sync_service = (ROOT / 'classes/Sync/GoogleBooksSyncService.php').read_text(encoding='utf-8')
check('OnixEnrichmentService' in sync_service and '$this->enrichment->enrich($book, $submission, $context, $defaultBisacCode)' in sync_service,
      'source-backed ONIX enrichments do not participate in synchronization fingerprints')

# 0.1.2.9+ strict Google Play profile regressions
check('ContributorRole A01 is required for Google Play Books' in validator,
      'Google profile validator does not require an A01 author')
check('promoteOrganizersWhenAuthorMissing' in mapper and 'ORGANIZER_ROLES' in mapper,
      'organized volumes do not receive the Google-facing A01 fallback')
check('GOOGLE_SUBJECT_SCHEMES' in validator and "'10'" in validator and "'78'" in validator,
      'Google-supported subject scheme whitelist is missing')
check('must contain SubjectCode' in validator,
      'Google profile validator does not require SubjectCode when Subject is present')
google_profile_test = (ROOT / 'tests' / 'onix_google_profile_smoke.php')
check(google_profile_test.is_file(), 'strict Google profile regression test is missing')
'''

needle = '\nif failures:\n'
if needle not in source:
    raise SystemExit('Unable to locate package-check result block')
source = source.replace(needle, extra + needle, 1)

# Execute with the wrapper path as __file__; both files share the tests/
# directory, so ROOT resolution remains identical to the original suite.
namespace = {
    '__name__': '__main__',
    '__file__': str(Path(__file__).resolve()),
}
exec(compile(source, str(source_path), 'exec'), namespace, namespace)
