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
    "check(vals.get('lazy-load') == '0', '0.1.2.6 must remain non-lazy to repair legacy enabled settings')",
)
source = source.replace(
    "check(vals.get('release') == '0.1.2.3', 'version.xml release mismatch')",
    "check(vals.get('release') == '0.1.2.6', 'version.xml release mismatch')",
)
source = source.replace('GoogleBooksIntegrationForOMP/0.1.2.3', 'GoogleBooksIntegrationForOMP/0.1.2.6')
source = source.replace(
    "check(vals.get('lazy-load') == '0', '0.1.2.5 must remain non-lazy to repair legacy enabled settings')",
    "check(vals.get('lazy-load') == '0', '0.1.2.6 must remain non-lazy to repair legacy enabled settings')",
)
source = source.replace(
    "check(vals.get('release') == '0.1.2.5', 'version.xml release mismatch')",
    "check(vals.get('release') == '0.1.2.6', 'version.xml release mismatch')",
)
source = source.replace('GoogleBooksIntegrationForOMP/0.1.2.5', 'GoogleBooksIntegrationForOMP/0.1.2.6')

extra = r'''
# 0.1.2.4+ live-onboarding regressions
check("return (bool) $this->plugin->getSetting($contextId, 'feedEnabled');" in feed,
      'HTTP feed is not controlled independently by feedEnabled')
check('DeliveryConfig::mode(' not in feed,
      'HTTP feed is incorrectly coupled to the selected push/staging delivery mode')
feed_manifest_v0126 = (ROOT / 'classes/Feed/FeedManifestService.php').read_text(encoding='utf-8')
check('VALIDATION_TARGET_COUNT = 10' in feed_manifest_v0126,
      'validation sample target is not 10 real products')
check('filterByStatus([Submission::STATUS_PUBLISHED])' in feed_manifest_v0126
      and 'validateMetadataBook' in feed_manifest_v0126,
      'validation sample does not supplement from real published metadata-valid OMP products')
check('new BookMetadata(' not in feed_manifest_v0126,
      'validation sample fabricates synthetic BookMetadata products')
check('array_values($books)' in feed_manifest_v0126,
      'validation sample does not emit the collected real product set')

# 0.1.2.6 Google final-verification regressions
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
check('assertDeliverableXml' in feed_manifest_v0126 and 'validateXml($xml)' in feed_manifest_v0126,
      'generated ONIX is not structurally validated before HTTP delivery')
check("substr_count($xml, '<Product>')" in feed_manifest_v0126
      and "substr_count($xml, '</Product>')" in feed_manifest_v0126,
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
