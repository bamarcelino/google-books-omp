#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[2]
p = root / 'tests/run.php'
s = p.read_text(encoding='utf-8')

old = """$editedVolume = clone $book;
$editedVolume->contributors = [['role' => 'B01', 'roles' => ['B01'], 'name' => 'Volume Editor', 'orcid' => null]];
check($validator->validateBook($editedVolume) === [], 'Validator rejected an editor-only book with one primary B01 role');
"""
new = """$editedVolume = clone $book;
$editedVolume->contributors = [['role' => 'B01', 'roles' => ['B01'], 'name' => 'Volume Editor', 'orcid' => null]];
check(in_array('At least one contributor with ContributorRole A01 is required for Google Play Books.', $validator->validateBook($editedVolume), true), 'Validator accepted an editor-only Google Play book without an A01 author');
"""
if old not in s:
    raise SystemExit('Unable to update editor-only validation expectation')
s = s.replace(old, new, 1)

old = """$enriched->subjects = [
    ['scheme' => '10', 'code' => 'SOC000000', 'heading' => null],
    ['scheme' => '20', 'code' => null, 'heading' => 'culture; education'],
];
"""
new = """$enriched->subjects = [
    ['scheme' => '10', 'code' => 'SOC000000', 'heading' => null],
];
"""
if old not in s:
    raise SystemExit('Unable to remove obsolete scheme-20 fixture')
s = s.replace(old, new, 1)

old = "check(str_contains($enrichedXml, '<SubjectSchemeIdentifier>20</SubjectSchemeIdentifier>') && str_contains($enrichedXml, '<SubjectHeadingText>culture; education</SubjectHeadingText>'), 'Keyword Subject enrichment is missing');\n"
new = "check(!str_contains($enrichedXml, '<SubjectSchemeIdentifier>20</SubjectSchemeIdentifier>') && !str_contains($enrichedXml, '<SubjectHeadingText>culture; education</SubjectHeadingText>'), 'Unsupported free-text Subject enrichment was emitted');\n"
if old not in s:
    raise SystemExit('Unable to update keyword Subject assertion')
s = s.replace(old, new, 1)

p.write_text(s, encoding='utf-8')
