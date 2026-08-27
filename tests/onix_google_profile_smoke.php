<?php

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
