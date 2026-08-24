#!/usr/bin/env python3
from pathlib import Path

# Keep the long-standing package contract test readable while applying the
# maintenance-release version markers in one place. This wrapper executes the
# previous contract suite with 0.1.2.4 expectations and adds the regression
# contracts introduced by the live Google onboarding fixes.
ROOT = Path(__file__).resolve().parents[1]
source_path = ROOT / 'tests' / 'package_check.py'
source = source_path.read_text(encoding='utf-8')

source = source.replace(
    "check(vals.get('lazy-load') == '0', '0.1.2.3 must remain non-lazy to repair legacy enabled settings')",
    "check(vals.get('lazy-load') == '0', '0.1.2.4 must remain non-lazy to repair legacy enabled settings')",
)
source = source.replace(
    "check(vals.get('release') == '0.1.2.3', 'version.xml release mismatch')",
    "check(vals.get('release') == '0.1.2.4', 'version.xml release mismatch')",
)
source = source.replace('GoogleBooksIntegrationForOMP/0.1.2.3', 'GoogleBooksIntegrationForOMP/0.1.2.4')

extra = r'''
# 0.1.2.4 live-onboarding regressions
check("return (bool) $this->plugin->getSetting($contextId, 'feedEnabled');" in feed,
      'HTTP feed is not controlled independently by feedEnabled')
check('DeliveryConfig::mode(' not in feed,
      'HTTP feed is incorrectly coupled to the selected push/staging delivery mode')
feed_manifest_v0124 = (ROOT / 'classes/Feed/FeedManifestService.php').read_text(encoding='utf-8')
check('VALIDATION_TARGET_COUNT = 10' in feed_manifest_v0124,
      'validation sample target is not 10 real products')
check('filterByStatus([Submission::STATUS_PUBLISHED])' in feed_manifest_v0124
      and 'validateMetadataBook' in feed_manifest_v0124,
      'validation sample does not supplement from real published metadata-valid OMP products')
check('new BookMetadata(' not in feed_manifest_v0124,
      'validation sample fabricates synthetic BookMetadata products')
check('array_values($books)' in feed_manifest_v0124,
      'validation sample does not emit the collected real product set')
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
