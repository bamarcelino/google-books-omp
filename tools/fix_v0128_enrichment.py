#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]

# ONIX 3.0 DescriptiveDetail ordering: Extent precedes Subject.
p = root / 'classes/Onix/GoogleOnixBuilder.php'
s = p.read_text(encoding='utf-8')
old = '''        foreach ($book->subjects as $subject) {
            $scheme = trim((string) ($subject['scheme'] ?? ''));
            $code = trim((string) ($subject['code'] ?? ''));
            $heading = trim((string) ($subject['heading'] ?? ''));
            if ($scheme === '' || ($code === '' && $heading === '')) { continue; }
            $xml .= "      <Subject>\\n";
            $xml .= Xml::element('SubjectSchemeIdentifier', $scheme, 4);
            if ($code !== '') { $xml .= Xml::element('SubjectCode', $code, 4); }
            if ($heading !== '') { $xml .= Xml::element('SubjectHeadingText', $heading, 4); }
            $xml .= "      </Subject>\\n";
        }

        foreach ($book->extents as $extent) {
            $type = trim((string) ($extent['type'] ?? ''));
            $value = trim((string) ($extent['value'] ?? ''));
            $unit = trim((string) ($extent['unit'] ?? ''));
            if ($type === '' || $value === '' || $unit === '') { continue; }
            $xml .= "      <Extent>\\n";
            $xml .= Xml::element('ExtentType', $type, 4);
            $xml .= Xml::element('ExtentValue', $value, 4);
            $xml .= Xml::element('ExtentUnit', $unit, 4);
            $xml .= "      </Extent>\\n";
        }
'''
new = '''        foreach ($book->extents as $extent) {
            $type = trim((string) ($extent['type'] ?? ''));
            $value = trim((string) ($extent['value'] ?? ''));
            $unit = trim((string) ($extent['unit'] ?? ''));
            if ($type === '' || $value === '' || $unit === '') { continue; }
            $xml .= "      <Extent>\\n";
            $xml .= Xml::element('ExtentType', $type, 4);
            $xml .= Xml::element('ExtentValue', $value, 4);
            $xml .= Xml::element('ExtentUnit', $unit, 4);
            $xml .= "      </Extent>\\n";
        }

        foreach ($book->subjects as $subject) {
            $scheme = trim((string) ($subject['scheme'] ?? ''));
            $code = trim((string) ($subject['code'] ?? ''));
            $heading = trim((string) ($subject['heading'] ?? ''));
            if ($scheme === '' || ($code === '' && $heading === '')) { continue; }
            $xml .= "      <Subject>\\n";
            $xml .= Xml::element('SubjectSchemeIdentifier', $scheme, 4);
            if ($code !== '') { $xml .= Xml::element('SubjectCode', $code, 4); }
            if ($heading !== '') { $xml .= Xml::element('SubjectHeadingText', $heading, 4); }
            $xml .= "      </Subject>\\n";
        }
'''
if old not in s:
    raise SystemExit('Could not locate Subject/Extent block')
s = s.replace(old, new, 1)
p.write_text(s, encoding='utf-8')

# RelatedProduct should point to canonical ISBNs of other publication formats,
# not to secondary identifiers attached to the same manifestation.
p = root / 'classes/Onix/OnixEnrichmentService.php'
s = p.read_text(encoding='utf-8')
old = '''    private function relatedProducts(array $formats, string $currentIsbn): array
    {
        $currentIsbn = IdentifierNormalizer::preferredIsbn13($currentIsbn) ?? $currentIsbn;
        $related = [];
        foreach ($formats as $format) {
            foreach ($this->formatIsbns($format) as $isbn13) {
                if ($isbn13 === $currentIsbn) {
                    continue;
                }
                $related[] = [
                    'relationCode' => '06', // alternative format
                    'isbn13' => $isbn13,
                ];
            }
        }
        return $this->uniqueRows($related);
    }
'''
new = '''    private function relatedProducts(array $formats, string $currentIsbn): array
    {
        $currentIsbn = IdentifierNormalizer::preferredIsbn13($currentIsbn) ?? $currentIsbn;
        $related = [];
        foreach ($formats as $format) {
            $isbn13 = $this->canonicalFormatIsbn($format);
            if ($isbn13 === null || $isbn13 === $currentIsbn) {
                continue;
            }
            $related[] = [
                'relationCode' => '06', // alternative format
                'isbn13' => $isbn13,
            ];
        }
        return $this->uniqueRows($related);
    }
'''
if old not in s:
    raise SystemExit('Could not locate relatedProducts block')
s = s.replace(old, new, 1)
insert_after = '''    private function formatIsbns(object $format): array
    {
        if (!method_exists($format, 'getIdentificationCodes')) {
            return [];
        }
        $isbns = [];
        $codes = $format->getIdentificationCodes();
        while ($code = $codes->next()) {
            if (!in_array((string) $code->getCode(), ['15', '03', '24', '02'], true)) {
                continue;
            }
            $isbn13 = IdentifierNormalizer::preferredIsbn13((string) $code->getValue());
            if ($isbn13 !== null && !in_array($isbn13, $isbns, true)) {
                $isbns[] = $isbn13;
            }
        }
        return $isbns;
    }
'''
addition = insert_after + '''
    private function canonicalFormatIsbn(object $format): ?string
    {
        if (!method_exists($format, 'getIdentificationCodes')) {
            return null;
        }
        $priorities = ['15' => 10, '03' => 20, '24' => 30, '02' => 40];
        $byPriority = [];
        $codes = $format->getIdentificationCodes();
        while ($code = $codes->next()) {
            $type = (string) $code->getCode();
            if (!isset($priorities[$type])) {
                continue;
            }
            $isbn13 = IdentifierNormalizer::preferredIsbn13((string) $code->getValue());
            if ($isbn13 !== null) {
                $byPriority[$priorities[$type]] ??= $isbn13;
            }
        }
        if ($byPriority === []) {
            return null;
        }
        ksort($byPriority, SORT_NUMERIC);
        return reset($byPriority) ?: null;
    }
'''
if insert_after not in s:
    raise SystemExit('Could not locate formatIsbns block')
s = s.replace(insert_after, addition, 1)
p.write_text(s, encoding='utf-8')

# Enriched output must pass generic XML/XSD validation, not only model checks.
p = root / 'tests/run.php'
s = p.read_text(encoding='utf-8')
needle = "check($validator->validateMetadataBook($enriched) === [], 'Validator rejected valid optional ONIX enrichments');"
replacement = needle + "\ncheck($validator->validateXml($enrichedXml) === [], 'XSD/runtime validator rejected enriched ONIX ordering or structure');"
if needle not in s:
    raise SystemExit('Could not locate enriched test assertion')
s = s.replace(needle, replacement, 1)
p.write_text(s, encoding='utf-8')

p = root / 'tests/onix_enrichment_source_smoke.php'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "    \"'relationCode' => '06'\" => 'alternative-format relation',\n",
    "    \"'relationCode' => '06'\" => 'alternative-format relation',\n    \"canonicalFormatIsbn\" => 'canonical alternative-format ISBN selection',\n"
)
p.write_text(s, encoding='utf-8')

p = root / 'tests/package_check_v0124.py'
s = p.read_text(encoding='utf-8')
s = s.replace(
    "check(\"'relationCode' => '06'\" in enrichment and 'formatIsbns' in enrichment,\n      'related edition ISBNs are not derived from actual OMP publication formats')",
    "check(\"'relationCode' => '06'\" in enrichment and 'canonicalFormatIsbn' in enrichment,\n      'related edition ISBNs are not derived from canonical OMP publication formats')"
)
p.write_text(s, encoding='utf-8')

# Remove one-shot tooling before the source commit.
Path(__file__).unlink()
workflow = root / '.github/workflows/fix-v0.1.2.8.yml'
if workflow.exists():
    workflow.unlink()
