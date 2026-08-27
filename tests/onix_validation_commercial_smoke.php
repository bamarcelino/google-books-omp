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
    if (!$condition) {
        $failures[] = $message;
    }
};

$isbn13 = static function (int $sequence): string {
    $base = '9784321' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    if (strlen($base) !== 12) {
        throw new RuntimeException('Invalid ISBN fixture base length.');
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += ((int) $base[$i]) * (($i % 2 === 0) ? 1 : 3);
    }
    return $base . ((10 - ($sum % 10)) % 10);
};

$books = [];
for ($i = 1; $i <= 10; $i++) {
    $isbn = $isbn13($i);
    $books[] = new BookMetadata(
        1,
        $i,
        $i,
        $isbn,
        null,
        'Google validation commercial sample ' . $i,
        null,
        [[
            'role' => 'A01',
            'roles' => ['A01'],
            'name' => 'Contributor ' . $i,
            'orcid' => null,
        ]],
        'Test Publisher',
        null,
        'pt_BR',
        '20260827',
        'A complete summary for Google Play Books validation.',
        null,
        true,
        [],
        [],
        null,
        null,
        null,
        [[
            'type' => '02',
            'countriesIncluded' => [],
            'regionsIncluded' => ['WORLD'],
            'countriesExcluded' => [],
            'regionsExcluded' => [],
        ]],
        [],
    );
}

$validator = new GoogleOnixValidator();
foreach ($books as $book) {
    $check(
        $validator->validateCommercialMetadataBook($book) === [],
        'A free validation product with WORLD non-exclusive rights was rejected.'
    );
}

$missingRights = clone $books[0];
$missingRights->salesRights = [];
$check(
    in_array('At least one SalesRights territory is required for Google Play Books.', $validator->validateCommercialMetadataBook($missingRights), true),
    'Validation metadata without SalesRights was accepted.'
);

$xml = (new GoogleOnixBuilder())->build(
    $books,
    'Test Publisher',
    'Test Contact',
    'test@example.invalid',
    new DateTimeImmutable('2026-08-27T19:00:00Z', new DateTimeZone('UTC')),
    true,
);

$check($validator->validateXml($xml) === [], 'Commercial validation sample failed generic XML/XSD validation.');
$check($validator->validateCommercialXml($xml) === [], 'Commercial validation sample failed Google Play commercial-profile validation.');
$check(substr_count($xml, '<Product>') === 10, 'Validation sample does not contain exactly 10 products.');
$check(substr_count($xml, '<SalesRights>') === 10, 'Every validation Product must contain SalesRights.');
$check(substr_count($xml, '<ProductSupply>') === 10, 'Every validation Product must contain ProductSupply.');
$check(substr_count($xml, '<Market>') === 10, 'Every validation ProductSupply must contain Market.');
$check(substr_count($xml, '<MarketPublishingDetail>') === 10, 'Every validation ProductSupply must contain MarketPublishingDetail.');
$check(substr_count($xml, '<SupplyDetail>') === 10, 'Every validation ProductSupply must contain SupplyDetail.');
$check(substr_count($xml, '<UnpricedItemType>01</UnpricedItemType>') === 10, 'Every free validation product must use UnpricedItemType 01.');
$check(substr_count($xml, '<Price>') === 0, 'Free validation products must not contain Price composites.');
$check(str_ends_with(trim($xml), '</ONIXMessage>'), 'Commercial validation ONIX is not completely closed.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'OK ' . $checks . " Google validation commercial-profile assertions\n";
