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
    $base = '9781234' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
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
for ($i = 1; $i <= 150; $i++) {
    $isbn = $isbn13($i);
    $books[] = new BookMetadata(
        1,
        $i,
        $i,
        $isbn,
        null,
        'Large catalogue regression title ' . $i,
        null,
        [[
            'role' => ($i % 2 === 0 ? 'A01' : 'B01'),
            'roles' => [($i % 2 === 0 ? 'A01' : 'B01')],
            'name' => 'Contributor ' . $i,
            'orcid' => null,
        ]],
        'Test Publisher',
        null,
        'pt_BR',
        '20260826',
        'Regression product used to verify that a large Google ONIX response is generated and closed in full.',
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

$xml = (new GoogleOnixBuilder())->build(
    $books,
    'Test Publisher',
    'Test Contact',
    'test@example.invalid',
    new DateTimeImmutable('2026-08-26T18:26:42Z', new DateTimeZone('UTC')),
    true,
);

$validator = new GoogleOnixValidator();
$errors = $validator->validateXml($xml);

$check($errors === [], 'Large 150-product ONIX document failed XML/XSD validation: ' . implode(' | ', $errors));
$check(substr_count($xml, '<Product>') === 150, 'Large ONIX did not contain 150 Product opening tags.');
$check(substr_count($xml, '</Product>') === 150, 'Large ONIX did not contain 150 Product closing tags.');
$check(str_ends_with(trim($xml), '</ONIXMessage>'), 'Large ONIX does not end with the ONIXMessage closing tag.');
$check(substr_count($xml, '<Contributor>') === 150, 'Large ONIX contributor count changed unexpectedly.');
$check(substr_count($xml, '<ContributorRole>') === 150, 'Large ONIX emitted more than one ContributorRole per Contributor.');
$check(substr_count($xml, '<UnpricedItemType>01</UnpricedItemType>') === 150, 'Free-of-charge products are not consistently represented with UnpricedItemType 01.');
$check(substr_count($xml, '<Price>') === 0, 'Free-of-charge products unexpectedly contain Price composites.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'OK ' . $checks . " large ONIX feed assertions\n";
