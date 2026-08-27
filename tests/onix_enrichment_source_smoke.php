<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/classes/Onix/OnixEnrichmentService.php');
$checks = [
    "['bisac', 'bisacCode', 'bisacCodes']" => 'explicit BISAC source fields',
    "['thema', 'themaCode', 'themaCodes']" => 'explicit Thema source fields',
    "['keywords', 'subjects', 'disciplines']" => 'OMP keyword/subject source fields',
    "'scheme' => '20'" => 'ONIX keyword scheme',
    "'type' => '00'" => 'main-content extent',
    "'type' => '03'" => 'front-matter extent',
    "'type' => '04'" => 'back-matter extent',
    "'relationCode' => '06'" => 'alternative-format relation',
    "canonicalFormatIsbn" => 'canonical alternative-format ISBN selection',
    "IdentifierNormalizer::preferredIsbn13" => 'normalized related ISBN validation',
];
$failed = [];
foreach ($checks as $needle => $label) {
    if (!str_contains($source, $needle)) {
        $failed[] = $label;
    }
}
if (str_contains($source, 'inferBisac') || str_contains($source, 'guessSubject') || str_contains($source, 'guessPage')) {
    $failed[] = 'synthetic metadata inference must not be introduced';
}
if ($failed) {
    fwrite(STDERR, "FAILED ONIX enrichment source checks: " . implode(', ', $failed) . "\n");
    exit(1);
}
echo "OK ONIX enrichment source-backed checks\n";
