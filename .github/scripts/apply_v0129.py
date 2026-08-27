#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[2]

# Google profile validator: require a real A01 author and only supported
# subject schemes with a SubjectCode.
p = root / 'classes/Onix/GoogleOnixValidator.php'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "final class GoogleOnixValidator\n{\n",
    "final class GoogleOnixValidator\n{\n"
    "    /** Subject schemes accepted by the current Google Play Books ONIX profile. */\n"
    "    private const GOOGLE_SUBJECT_SCHEMES = [\n"
    "        '01', '03', '04', '09', '10', '23', '24', '26', '29',\n"
    "        '33', '40', '53', '54', '55', '56', '57', '78',\n"
    "    ];\n\n",
    1,
)
s = s.replace(
    "        if ($book->contributors === []) {\n"
    "            $errors[] = 'At least one contributor is required.';\n"
    "        }\n"
    "        foreach ($book->contributors as $contributor) {",
    "        if ($book->contributors === []) {\n"
    "            $errors[] = 'At least one contributor is required.';\n"
    "        }\n"
    "        $hasGooglePlayAuthor = false;\n"
    "        foreach ($book->contributors as $contributor) {",
    1,
)
s = s.replace(
    "            foreach ($roles as $role) {\n"
    "                if (!preg_match('/^[A-Z][0-9]{2}$/', $role)) {",
    "            foreach ($roles as $role) {\n"
    "                if ($role === 'A01') {\n"
    "                    $hasGooglePlayAuthor = true;\n"
    "                }\n"
    "                if (!preg_match('/^[A-Z][0-9]{2}$/', $role)) {",
    1,
)
s = s.replace(
    "            if (!empty($contributor['orcid']) && IdentifierNormalizer::normalizeOrcid((string) $contributor['orcid']) === null) {\n"
    "                $errors[] = 'Contributor ORCID is invalid.';\n"
    "            }\n"
    "        }\n\n"
    "        if ($book->seriesIssn !== null",
    "            if (!empty($contributor['orcid']) && IdentifierNormalizer::normalizeOrcid((string) $contributor['orcid']) === null) {\n"
    "                $errors[] = 'Contributor ORCID is invalid.';\n"
    "            }\n"
    "        }\n"
    "        if (!$hasGooglePlayAuthor) {\n"
    "            $errors[] = 'At least one contributor with ContributorRole A01 is required for Google Play Books.';\n"
    "        }\n\n"
    "        if ($book->seriesIssn !== null",
    1,
)
old_subject = """        foreach ($book->subjects as $subject) {
            $scheme = trim((string) ($subject['scheme'] ?? ''));
            $code = trim((string) ($subject['code'] ?? ''));
            $heading = trim((string) ($subject['heading'] ?? ''));
            if (!preg_match('/^\\d{2}$/', $scheme) || ($code === '' && $heading === '')) { $errors[] = 'Every ONIX Subject must contain a two-digit scheme and either SubjectCode or SubjectHeadingText.'; }
        }
"""
new_subject = """        foreach ($book->subjects as $subject) {
            $scheme = trim((string) ($subject['scheme'] ?? ''));
            $code = strtoupper(trim((string) ($subject['code'] ?? '')));
            if (!in_array($scheme, self::GOOGLE_SUBJECT_SCHEMES, true)) {
                $errors[] = 'Every ONIX Subject sent to Google Play Books must use a supported SubjectSchemeIdentifier.';
                continue;
            }
            if ($code === '') {
                $errors[] = 'Every ONIX Subject sent to Google Play Books must contain SubjectCode.';
                continue;
            }
            if ($scheme === '10' && !preg_match('/^[A-Z]{3}[0-9]{6}$/', $code)) {
                $errors[] = 'BISAC SubjectCode must contain three letters followed by six digits.';
            }
        }
"""
if old_subject not in s:
    raise SystemExit('Unable to patch Subject validation block')
s = s.replace(old_subject, new_subject, 1)
old_dom = """                    foreach ($xpath->query('//onix:Contributor') ?: [] as $contributorNode) {
                        if ($xpath->query('./onix:ContributorRole', $contributorNode)->length !== 1) {
                            $errors[] = 'Google Play Books profile requires exactly one ContributorRole in each Contributor composite.';
                            break;
                        }
                    }
                    $xsdPath = $this->ompOnixSchemaPath();
"""
new_dom = """                    foreach ($xpath->query('//onix:Contributor') ?: [] as $contributorNode) {
                        if ($xpath->query('./onix:ContributorRole', $contributorNode)->length !== 1) {
                            $errors[] = 'Google Play Books profile requires exactly one ContributorRole in each Contributor composite.';
                            break;
                        }
                    }
                    foreach ($xpath->query('/onix:ONIXMessage/onix:Product') ?: [] as $productNode) {
                        $record = trim((string) $xpath->evaluate('string(./onix:RecordReference)', $productNode));
                        $label = $record !== '' ? $record : 'unknown product';
                        if ($xpath->query('./onix:DescriptiveDetail/onix:Contributor[onix:ContributorRole="A01"]', $productNode)->length === 0) {
                            $errors[] = $label . ': Google Play Books requires at least one A01 author.';
                        }
                        foreach ($xpath->query('./onix:DescriptiveDetail/onix:Subject', $productNode) ?: [] as $subjectNode) {
                            $scheme = trim((string) $xpath->evaluate('string(./onix:SubjectSchemeIdentifier)', $subjectNode));
                            $code = trim((string) $xpath->evaluate('string(./onix:SubjectCode)', $subjectNode));
                            if (!in_array($scheme, self::GOOGLE_SUBJECT_SCHEMES, true)) {
                                $errors[] = $label . ': unsupported Google Play SubjectSchemeIdentifier ' . ($scheme !== '' ? $scheme : '(missing)') . '.';
                            }
                            if ($code === '') {
                                $errors[] = $label . ': every Google Play Subject composite requires SubjectCode.';
                            }
                            if ($scheme === '10' && $code !== '' && !preg_match('/^[A-Z]{3}[0-9]{6}$/', strtoupper($code))) {
                                $errors[] = $label . ': invalid BISAC SubjectCode.';
                            }
                        }
                    }
                    $xsdPath = $this->ompOnixSchemaPath();
"""
if old_dom not in s:
    raise SystemExit('Unable to patch XML profile validation block')
s = s.replace(old_dom, new_dom, 1)
p.write_text(s, encoding='utf-8')

# Enrichment: do not turn OMP free-text keywords into a Google Subject
# composite. Emit a Subject only from an explicit valid BISAC code.
p = root / 'classes/Onix/OnixEnrichmentService.php'
s = p.read_text(encoding='utf-8')
start = s.index("    /** @return array<int,array{scheme:string,code:?string,heading:?string}> */\n    private function subjects")
end = s.index("    /** @return array<int,array{type:string,value:string,unit:string}> */\n    private function extents", start)
method = r'''    /** @return array<int,array{scheme:string,code:?string,heading:?string}> */
    private function subjects(object $publication, string $locale): array
    {
        $subjects = [];

        // Google Play requires SubjectCode whenever Subject is present. OMP's
        // normal keywords/subjects/disciplines are free text, not controlled
        // category codes, so they must not be repackaged as a Google Subject.
        // Export only explicit BISAC fields whose value is structurally valid.
        foreach (['bisac', 'bisacCode', 'bisacCodes'] as $field) {
            foreach ($this->metadataValues($publication, $field, $locale) as $value) {
                $code = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);
                if (!preg_match('/^[A-Z]{3}[0-9]{6}$/', $code)) {
                    continue;
                }
                $subjects[] = ['scheme' => '10', 'code' => $code, 'heading' => null];
            }
        }

        return $this->uniqueRows($subjects);
    }

'''
s = s[:start] + method + s[end:]
p.write_text(s, encoding='utf-8')

# Builder: a Subject without SubjectCode is invalid in Google's profile.
p = root / 'classes/Onix/GoogleOnixBuilder.php'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "            if ($scheme === '' || ($code === '' && $heading === '')) { continue; }",
    "            if ($scheme === '' || $code === '') { continue; }",
    1,
)
p.write_text(s, encoding='utf-8')

# Validation sample diagnostics should explicitly mention A01.
p = root / 'classes/Feed/FeedManifestService.php'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "valid ISBN, bibliographic metadata, SalesRights and supply terms; only %d eligible product(s) were found.",
    "valid ISBN, at least one A01 author, bibliographic metadata, SalesRights and supply terms; only %d eligible product(s) were found.",
    1,
)
p.write_text(s, encoding='utf-8')

# Source-contract test.
p = root / 'tests/onix_enrichment_source_smoke.php'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "    \"['thema', 'themaCode', 'themaCodes']\" => 'explicit Thema source fields',\n"
    "    \"['keywords', 'subjects', 'disciplines']\" => 'OMP keyword/subject source fields',\n"
    "    \"'scheme' => '20'\" => 'ONIX keyword scheme',\n",
    "",
    1,
)
s = s.replace(
    "if (str_contains($source, 'inferBisac') || str_contains($source, 'guessSubject') || str_contains($source, 'guessPage')) {\n"
    "    $failed[] = 'synthetic metadata inference must not be introduced';\n"
    "}\n",
    "if (str_contains($source, \"'scheme' => '20'\") || str_contains($source, \"['thema', 'themaCode', 'themaCodes']\") || str_contains($source, \"['keywords', 'subjects', 'disciplines']\")) {\n"
    "    $failed[] = 'unsupported/free-text subject export must not be introduced';\n"
    "}\n"
    "if (str_contains($source, 'inferBisac') || str_contains($source, 'guessSubject') || str_contains($source, 'guessPage')) {\n"
    "    $failed[] = 'synthetic metadata inference must not be introduced';\n"
    "}\n",
    1,
)
p.write_text(s, encoding='utf-8')

# Exact Google-profile regression test.
p = root / 'tests/onix_google_profile_smoke.php'
p.write_text(r'''<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixBuilder;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixValidator;
use DateTimeImmutable;
use DateTimeZone;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) { $failures[] = $message; }
};

$book = new BookMetadata(
    1, 1, 1, '9786599454448', null,
    'Google Play profile fixture', null,
    [[
        'role' => 'A01',
        'roles' => ['A01'],
        'name' => 'Real Author',
        'orcid' => null,
    ]],
    'Test Publisher', null, 'pt_BR', '20260827',
    'A real summary.', null, true, [], [], null, null, null,
    [[
        'type' => '02',
        'countriesIncluded' => [],
        'regionsIncluded' => ['WORLD'],
        'countriesExcluded' => [],
        'regionsExcluded' => [],
    ]],
    [],
);
$book->subjects = [[
    'scheme' => '10',
    'code' => 'SOC026000',
    'heading' => null,
]];

$validator = new GoogleOnixValidator();
$check($validator->validateCommercialMetadataBook($book) === [], 'Valid A01/BISAC commercial product was rejected.');

$noAuthor = clone $book;
$noAuthor->contributors = [[
    'role' => 'B01',
    'roles' => ['B01'],
    'name' => 'Editor Only',
    'orcid' => null,
]];
$noAuthorErrors = $validator->validateCommercialMetadataBook($noAuthor);
$check(in_array('At least one contributor with ContributorRole A01 is required for Google Play Books.', $noAuthorErrors, true), 'B01-only product was accepted without an A01 author.');

$keywordSubject = clone $book;
$keywordSubject->subjects = [[
    'scheme' => '20',
    'code' => null,
    'heading' => 'Education; public policy',
]];
$keywordErrors = $validator->validateMetadataBook($keywordSubject);
$check(in_array('Every ONIX Subject sent to Google Play Books must use a supported SubjectSchemeIdentifier.', $keywordErrors, true), 'Unsupported scheme 20 was accepted.');

$headingOnly = clone $book;
$headingOnly->subjects = [[
    'scheme' => '10',
    'code' => null,
    'heading' => 'SOCIAL SCIENCE',
]];
$headingErrors = $validator->validateMetadataBook($headingOnly);
$check(in_array('Every ONIX Subject sent to Google Play Books must contain SubjectCode.', $headingErrors, true), 'Heading-only Subject was accepted without SubjectCode.');

$thema = clone $book;
$thema->subjects = [[
    'scheme' => '93',
    'code' => 'JBSF1',
    'heading' => null,
]];
$themaErrors = $validator->validateMetadataBook($thema);
$check(in_array('Every ONIX Subject sent to Google Play Books must use a supported SubjectSchemeIdentifier.', $themaErrors, true), 'Unsupported scheme 93 was accepted by the strict Google profile.');

$xml = (new GoogleOnixBuilder())->build(
    [$book], 'Test Publisher', 'Test Contact', 'test@example.invalid',
    new DateTimeImmutable('2026-08-27T20:00:00Z', new DateTimeZone('UTC')), true,
);
$check($validator->validateXml($xml) === [], 'Valid Google-profile XML failed XML/XSD/profile validation.');
$check(str_contains($xml, '<SubjectSchemeIdentifier>10</SubjectSchemeIdentifier>'), 'Valid BISAC Subject was not emitted.');
$check(str_contains($xml, '<SubjectCode>SOC026000</SubjectCode>'), 'Valid BISAC SubjectCode was not emitted.');
$check(!str_contains($xml, '<SubjectHeadingText>'), 'Empty/unneeded SubjectHeadingText was emitted.');

$badXml = (new GoogleOnixBuilder())->build(
    [$noAuthor], 'Test Publisher', 'Test Contact', 'test@example.invalid',
    new DateTimeImmutable('2026-08-27T20:00:00Z', new DateTimeZone('UTC')), true,
);
$badXmlErrors = $validator->validateXml($badXml);
$check((bool) array_filter($badXmlErrors, static fn(string $e): bool => str_contains($e, 'requires at least one A01 author')), 'XML boundary did not reject a product without A01.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'OK ' . $checks . " Google Play profile assertions\n";
''', encoding='utf-8')

p = root / 'tests/run_all.sh'
s = p.read_text(encoding='utf-8')
needle = "php tests/onix_validation_commercial_smoke.php\n"
if "php tests/onix_google_profile_smoke.php" not in s:
    s = s.replace(needle, needle + "php tests/onix_google_profile_smoke.php\n", 1)
p.write_text(s, encoding='utf-8')

# Package/source contracts and version markers.
p = root / 'tests/package_check_v0124.py'
s = p.read_text(encoding='utf-8').replace('0.1.2.8', '0.1.2.9')
s = s.replace(
    "check(\"['keywords', 'subjects', 'disciplines']\" in enrichment and \"'scheme' => '20'\" in enrichment,\n"
    "      'OMP free-text subjects are not exported truthfully as ONIX keywords')\n"
    "check(\"['bisac', 'bisacCode', 'bisacCodes']\" in enrichment and \"['thema', 'themaCode', 'themaCodes']\" in enrichment,\n"
    "      'explicit publisher BISAC/Thema fields are not recognized')\n",
    "check(\"['bisac', 'bisacCode', 'bisacCodes']\" in enrichment and \"'scheme' => '10'\" in enrichment,\n"
    "      'explicit publisher BISAC fields are not recognized')\n"
    "check(\"'scheme' => '20'\" not in enrichment and \"['keywords', 'subjects', 'disciplines']\" not in enrichment and \"['thema', 'themaCode', 'themaCodes']\" not in enrichment,\n"
    "      'unsupported/free-text Google subject export is still present')\n",
    1,
)
marker = "check('guessSubject' not in enrichment and 'guessPage' not in enrichment,\n      'ONIX enrichment introduced synthetic metadata inference')\n"
addition = marker + "\n# 0.1.2.9 strict Google Play profile regressions\ncheck('ContributorRole A01 is required for Google Play Books' in validator,\n      'Google profile validator does not require an A01 author')\ncheck('GOOGLE_SUBJECT_SCHEMES' in validator and \"'10'\" in validator and \"'78'\" in validator,\n      'Google-supported subject scheme whitelist is missing')\ncheck('must contain SubjectCode' in validator,\n      'Google profile validator does not require SubjectCode when Subject is present')\ngoogle_profile_test = (ROOT / 'tests' / 'onix_google_profile_smoke.php')\ncheck(google_profile_test.is_file(), 'strict Google profile regression test is missing')\n"
if marker not in s:
    raise SystemExit('Unable to extend package contract checks')
s = s.replace(marker, addition, 1)
p.write_text(s, encoding='utf-8')

p = root / 'version.xml'
s = p.read_text(encoding='utf-8').replace('<release>0.1.2.8</release>', '<release>0.1.2.9</release>')
p.write_text(s, encoding='utf-8')

p = root / 'classes/Api/GoogleBooksApiClient.php'
s = p.read_text(encoding='utf-8').replace('GoogleBooksIntegrationForOMP/0.1.2.8', 'GoogleBooksIntegrationForOMP/0.1.2.9')
p.write_text(s, encoding='utf-8')

p = root / 'classes/DashboardHandler.php'
s = p.read_text(encoding='utf-8').replace("$assetVersion = '0.1.2.3';", "$assetVersion = '0.1.2.9';")
p.write_text(s, encoding='utf-8')

(root / 'RELEASE_NOTES_v0.1.2.9.md').write_text('''# Google Books Integration for OMP 0.1.2.9

This maintenance release aligns the generated ONIX more strictly with the current Google Play Books ingestion profile after live CLAEC validation.

## Google Play profile corrections

- Requires at least one real `A01` author on every Google-eligible product. Editor-only (`B01`) records are no longer treated as Google Play eligible.
- Requires every emitted `Subject` to use a Google-supported `SubjectSchemeIdentifier` and to contain `SubjectCode`.
- Stops converting ordinary OMP keywords, subjects and disciplines into heading-only ONIX scheme `20` subjects.
- Stops exporting Thema scheme `93` in the strict Google feed profile.
- Keeps explicit valid BISAC codes (`SubjectSchemeIdentifier 10`) when the publisher has stored them.
- Preserves source-backed page extents, related-format ISBNs and summaries when real OMP metadata exists.
- Preserves the validated commercial profile: `SalesRights`, `ProductSupply`, WORLD markets and `UnpricedItemType 01` for free books.
- Adds model-level and final XML-boundary checks so a missing A01 or invalid Subject cannot be delivered silently.

No database migration is required.
''', encoding='utf-8')

p = root / 'CHANGELOG.md'
if p.exists():
    s = p.read_text(encoding='utf-8')
    heading = "## 0.1.2.9 - 2026-08-27\n\n- Align Google Play eligibility with the required A01 author role.\n- Emit only coded, Google-supported ONIX subjects; do not convert free-text OMP keywords to scheme 20.\n- Add strict Google-profile regression checks while preserving commercial feed fixes and source-backed optional enrichments.\n\n"
    if '## 0.1.2.9' not in s:
        pos = s.find('\n## ')
        if pos >= 0:
            s = s[:pos+1] + '\n' + heading + s[pos+1:]
        else:
            s = s.rstrip() + '\n\n' + heading
        p.write_text(s, encoding='utf-8')
