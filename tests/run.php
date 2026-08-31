<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use APP\plugins\generic\googleBooks\classes\Api\GoogleBooksApiClient;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\classes\Delivery\TransportCapabilities;
use APP\plugins\generic\googleBooks\classes\Feed\BasicAuth;
use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixBuilder;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixValidator;
use APP\plugins\generic\googleBooks\classes\Sync\OmpBookMapper;
use APP\plugins\generic\googleBooks\classes\Jobs\BookVerificationJob;
use APP\plugins\generic\googleBooks\classes\Jobs\CatalogVerifyJob;
use APP\plugins\generic\googleBooks\classes\Security\SecretStore;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;

$tests = 0;
$failures = [];

function check(bool $condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $message;
    }
}

$canonical = '9780306406157';
check(IdentifierNormalizer::preferredIsbn13('978-0-306-40615-7') === $canonical, 'hyphenated ISBN-13 normalization failed');
check(IdentifierNormalizer::preferredIsbn13('978.0.306.40615.7') === $canonical, 'dotted ISBN-13 normalization failed');
check(IdentifierNormalizer::preferredIsbn13('978 0 306 40615 7') === $canonical, 'spaced ISBN-13 normalization failed');
check(IdentifierNormalizer::preferredIsbn13('0-306-40615-2') === $canonical, 'ISBN-10 to ISBN-13 conversion failed');
check(in_array('0306406152', IdentifierNormalizer::isbnEquivalents($canonical), true), 'ISBN equivalent set did not include ISBN-10');
check(IdentifierNormalizer::preferredIsbn13('978.0-306 40615.7') === $canonical, 'mixed-punctuation ISBN normalization failed');
check(IdentifierNormalizer::preferredIsbn13('ISBN 978.0.306.40615.7') === $canonical, 'labeled ISBN normalization failed');
check(IdentifierNormalizer::preferredIsbn13('ISBN-13: 978-0-306-40615-7') === $canonical, 'ISBN-13 label normalization failed');
check(IdentifierNormalizer::normalizeIsbn('ISBN 10: 0-306-40615-2') === '0306406152', 'ISBN-10 label normalization failed');
check(IdentifierNormalizer::preferredIsbn13('4006381333931') === null, 'a valid non-ISBN GTIN-13 was accepted as ISBN-13');
check(IdentifierNormalizer::preferredIsbn13('9791234567896') === '9791234567896', 'valid 979 ISBN-13 was rejected');
check(IdentifierNormalizer::normalizeIssn('2049-3630') === '20493630', 'hyphenated ISSN normalization failed');
check(IdentifierNormalizer::normalizeIssn('2049.3630') === '20493630', 'dotted ISSN normalization failed');
check(IdentifierNormalizer::normalizeIssn('2049 3630') === '20493630', 'spaced ISSN normalization failed');
check(IdentifierNormalizer::normalizeIssn('ISSN: 2049.3630') === '20493630', 'ISSN label normalization failed');
check(IdentifierNormalizer::normalizeIssn('ISSN-L: 2049-3630') === '20493630', 'ISSN-L label normalization failed');
check(IdentifierNormalizer::normalizeIssn('ISSN L: 2049-3630') === '20493630', 'spaced ISSN-L label normalization failed');
check(IdentifierNormalizer::normalizeIssn('eISSN: 2049.3630') === '20493630', 'eISSN label normalization failed');
check(IdentifierNormalizer::normalizeIssn('pISSN 2049-3630') === '20493630', 'pISSN label normalization failed');
check(IdentifierNormalizer::formatIssn('20493630') === '2049-3630', 'ISSN formatting failed');
check(IdentifierNormalizer::normalizeIssn('2434.561X') === '2434561X', 'ISSN X-check-digit normalization failed');
check(IdentifierNormalizer::normalizeIssn('2049-3631') === null, 'invalid ISSN checksum was accepted');
check(IdentifierNormalizer::normalizeOrcid('https://orcid.org/0000-0002-1825-0097') === '0000000218250097', 'ORCID URL normalization failed');
check(IdentifierNormalizer::formatOrcid('0000000218250097') === '0000-0002-1825-0097', 'ORCID formatting failed');
check(IdentifierNormalizer::normalizeOrcid('0000-0002-1825-0098') === null, 'invalid ORCID checksum was accepted');

unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('crawler:secret123');
[$authUser, $authPassword] = BasicAuth::credentials();
check($authUser === 'crawler' && $authPassword === 'secret123', 'HTTP Authorization Basic credentials were not decoded');
$authHash = password_hash('secret123', PASSWORD_DEFAULT);
check(BasicAuth::check('crawler', $authHash) === true, 'Valid feed Basic Auth credentials were rejected');
check(BasicAuth::check('other', $authHash) === false, 'Feed Basic Auth accepted the wrong username');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('crawler:wrong');
check(BasicAuth::check('crawler', $authHash) === false, 'Feed Basic Auth accepted the wrong password');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic !!!not-base64!!!';
check(BasicAuth::credentials() === [null, null], 'Malformed Basic Auth header was accepted');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['PHP_AUTH_USER'] = 'nativeUser';
$_SERVER['PHP_AUTH_PW'] = 'nativePass';
check(BasicAuth::credentials() === ['nativeUser', 'nativePass'], 'Native PHP Basic Auth variables were not preferred');
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

$partialNativeServer = [
    'PHP_AUTH_USER' => 'partialNative',
    'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('headerUser:headerPass'),
];
check(BasicAuth::credentials($partialNativeServer, []) === ['headerUser', 'headerPass'], 'Partial native Basic Auth prevented Authorization-header fallback');
check(BasicAuth::credentials([], ['authorization' => 'Basic ' . base64_encode('headerOnly:secret')]) === ['headerOnly', 'secret'], 'Case-insensitive request-header Basic Auth fallback failed');
check(BasicAuth::credentials([], ['AUTHORIZATION' => 'Basic ' . base64_encode('upperHeader:secret2')]) === ['upperHeader', 'secret2'], 'Uppercase request-header Basic Auth fallback failed');
check(BasicAuth::credentials(['REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('redirectUser:redirectPass')], []) === ['redirectUser', 'redirectPass'], 'Redirected Authorization Basic credentials were not decoded');
check(BasicAuth::credentials(['PHP_AUTH_USER' => 'orphanUser'], [], []) === ['orphanUser', ''], 'Orphan native Basic Auth username fallback changed unexpectedly');
check(BasicAuth::credentials([], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('envUser:envPass')]) === ['envUser', 'envPass'], 'Environment Authorization Basic credentials were not decoded');
check(BasicAuth::credentials(['FCGI_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('fcgiUser:fcgiPass')], [], []) === ['fcgiUser', 'fcgiPass'], 'FCGI Authorization Basic credentials were not decoded');

$diagnosticHash = password_hash('diagnosticPass', PASSWORD_DEFAULT);
$diagnostic = BasicAuth::diagnostic(
    'diagnosticUser',
    $diagnosticHash,
    ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('diagnosticUser:diagnosticPass')],
    [],
    [],
);
check(($diagnostic['authenticated'] ?? false) === true, 'Authentication diagnostic did not recognize valid credentials');
check(($diagnostic['authorizationPresent'] ?? false) === true && ($diagnostic['authorizationSource'] ?? '') === 'server:HTTP_AUTHORIZATION', 'Authentication diagnostic did not identify the Authorization source');
check(($diagnostic['authorizationIsBasic'] ?? false) === true && ($diagnostic['authorizationDecoded'] ?? false) === true, 'Authentication diagnostic did not report a decodable Basic header');
check(($diagnostic['usernameMatches'] ?? false) === true && ($diagnostic['passwordMatches'] ?? false) === true, 'Authentication diagnostic did not compare credentials safely');
check(!array_key_exists('user', $diagnostic) && !array_key_exists('password', $diagnostic) && !array_key_exists('passwordHash', $diagnostic) && !array_key_exists('authorization', $diagnostic), 'Authentication diagnostic exposed credential material');
$diagnosticWrongPassword = BasicAuth::diagnostic(
    'diagnosticUser',
    $diagnosticHash,
    ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('diagnosticUser:wrong')],
    [],
    [],
);
check(($diagnosticWrongPassword['usernameMatches'] ?? false) === true && ($diagnosticWrongPassword['passwordMatches'] ?? true) === false && ($diagnosticWrongPassword['authenticated'] ?? true) === false, 'Authentication diagnostic did not isolate a password mismatch');
$diagnosticNoHeader = BasicAuth::diagnostic('diagnosticUser', $diagnosticHash, [], [], []);
check(($diagnosticNoHeader['authorizationPresent'] ?? true) === false && ($diagnosticNoHeader['usernamePresent'] ?? true) === false && ($diagnosticNoHeader['passwordPresent'] ?? true) === false, 'Authentication diagnostic did not identify a missing Authorization header');

$requests = [];
$transport = static function (string $url, array $query) use (&$requests): array {
    $requests[] = [$url, $query];
    return [
        'items' => [[
            'id' => 'volume123',
            'selfLink' => 'https://www.googleapis.com/books/v1/volumes/volume123',
            'volumeInfo' => [
                'title' => 'Test Book',
                'publisher' => 'Test Publisher',
                'industryIdentifiers' => [
                    ['type' => 'ISBN_13', 'identifier' => '978-0-306-40615-7'],
                    ['type' => 'ISBN_10', 'identifier' => '0.306.40615.2'],
                ],
                'infoLink' => 'https://books.google.test/info',
                'previewLink' => 'https://books.google.test/preview',
            ],
        ]],
    ];
};
$client = new GoogleBooksApiClient('apiKey', 'partnerId', $transport);
$match = $client->findByIsbn('978.0.306.40615.7');
check($match->found === true, 'Google Books exact ISBN discovery failed');
check($match->volumeId === 'volume123', 'Google Books Volume ID was not captured');
check($match->infoLink === 'https://books.google.test/info', 'Safe Google Books info URL was not captured');
check(isset($requests[0][1]['q']) && $requests[0][1]['q'] === 'isbn:9780306406157', 'Google Books ISBN query was not canonicalized');
check(($requests[0][1]['key'] ?? null) === 'apiKey', 'Google Books API key was not propagated');
check(!isset($requests[0][1]['partner']), 'Primary Google discovery query was incorrectly restricted to one partner catalogue');
check(count($requests) === 1, 'An exact ISBN-13 match should not issue redundant ISBN-10 or partner fallback requests');

$fallbackRequests = [];
$partnerFallbackClient = new GoogleBooksApiClient('apiKey', 'partnerId', static function (string $url, array $query) use (&$fallbackRequests): array {
    $fallbackRequests[] = [$url, $query];
    if (!isset($query['partner'])) {
        return [];
    }
    return ['items' => [[
        'id' => 'partnerVolume',
        'volumeInfo' => [
            'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
        ],
    ]]];
});
$partnerMatch = $partnerFallbackClient->findByIsbn($canonical);
check($partnerMatch->found && $partnerMatch->volumeId === 'partnerVolume', 'Partner-restricted fallback discovery failed');
check((bool) array_filter($fallbackRequests, static fn (array $request): bool => ($request[1]['partner'] ?? null) === 'partnerId'), 'Google Books partner fallback query was not issued');

$nonMatchClient = new GoogleBooksApiClient(null, null, static function (): array {
    return [
        'items' => [[
            'id' => 'wrong',
            'volumeInfo' => [
                'industryIdentifiers' => [
                    ['type' => 'ISBN_13', 'identifier' => '9780131103627'],
                ],
            ],
        ]],
    ];
});
check($nonMatchClient->findByIsbn($canonical)->found === false, 'Discovery accepted a non-matching ISBN result');

$unsafeUrlClient = new GoogleBooksApiClient(null, null, static function (): array {
    return ['items' => [[
        'id' => 'safeVolume',
        'selfLink' => 'javascript:alert(1)',
        'volumeInfo' => [
            'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
            'infoLink' => 'javascript:alert(1)',
            'previewLink' => 'https://books.google.test/preview',
        ],
    ]]];
});
$unsafeUrlMatch = $unsafeUrlClient->findByIsbn($canonical);
check($unsafeUrlMatch->found === true && $unsafeUrlMatch->infoLink === null && $unsafeUrlMatch->selfLink === null && $unsafeUrlMatch->previewLink !== null, 'Unsafe non-HTTP Google URLs were not rejected');

$missingIdClient = new GoogleBooksApiClient(null, null, static function (): array {
    return ['items' => [[
        'volumeInfo' => [
            'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']],
        ],
    ]]];
});
check($missingIdClient->findByIsbn($canonical)->found === false, 'Google discovery linked an item without a Volume ID');

$ambiguousClient = new GoogleBooksApiClient(null, null, static function (): array {
    return [
        'items' => [
            ['id' => 'dupA', 'volumeInfo' => ['industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780306406157']]]],
            ['id' => 'dupB', 'volumeInfo' => ['industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '978-0-306-40615-7']]]],
        ],
    ];
});
$ambiguousMatch = $ambiguousClient->findByIsbn($canonical);
check($ambiguousMatch->found === false && $ambiguousMatch->ambiguous === true && $ambiguousMatch->candidateCount === 2, 'Multiple exact Google Volume IDs were not flagged as ambiguous');

$apiErrorClient = new GoogleBooksApiClient('SUPERSECRETAPIKEY', null, static function (): array {
    throw new RuntimeException('request failed at https://www.googleapis.com/books/v1/volumes?key=SUPERSECRETAPIKEY');
});
try {
    $apiErrorClient->findByIsbn($canonical);
    check(false, 'Google Books API transport failure was not raised');
} catch (RuntimeException $e) {
    check(!str_contains($e->getMessage(), 'SUPERSECRETAPIKEY'), 'Google Books API error exposed the API key');
}

$assets = [
    ['kind' => 'content', 'fileId' => 1, 'formatId' => 10, 'path' => 'test/book.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf', 'size' => 100, 'modified' => 1000, 'filename' => $canonical . '.pdf'],
    ['kind' => 'content', 'fileId' => 2, 'formatId' => 11, 'path' => 'test/book.epub', 'mime' => 'application/epub+zip', 'extension' => 'epub', 'size' => 200, 'modified' => 1001, 'filename' => $canonical . '.epub'],
];
$worldRights = [[
    'type' => '02',
    'countriesIncluded' => [],
    'regionsIncluded' => ['WORLD'],
    'countriesExcluded' => [],
    'regionsExcluded' => [],
]];
$book = new BookMetadata(
    1,
    2,
    3,
    $canonical,
    '0306406152',
    'The Test Book',
    'A Subtitle',
    [['role' => 'A01', 'roles' => ['A01'], 'name' => 'Jane Doe', 'orcid' => 'https://orcid.org/0000-0002-1825-0097']],
    'Test Publisher',
    'Test Imprint',
    'en_US',
    '20260813',
    'A test description.',
    'https://creativecommons.org/licenses/by/4.0/',
    true,
    [],
    $assets,
    'Test Series',
    '20493630',
    'OMP1S77',
    $worldRights,
    [],
);
$builder = new GoogleOnixBuilder();
$xml = $builder->build([$book], 'Test Publisher', 'Editor Name', 'editor@example.org', new \DateTimeImmutable('2026-08-13 16:30:45', new \DateTimeZone('UTC')));
file_put_contents(__DIR__ . '/generated-onix.xml', $xml);
$validator = new GoogleOnixValidator();
check($validator->validateBook($book) === [], 'Book validator rejected valid metadata');
check($validator->validateRightsBook($book) === [], 'Rights validator rejected valid free worldwide rights metadata');
$metadataOnlyBook = clone $book;
$metadataOnlyBook->assets = [];
$metadataOnlyBook->salesRights = [];
$metadataOnlyBook->markets = [];
check($validator->validateMetadataBook($metadataOnlyBook) === [], 'Metadata-only Google ONIX validation rejected required bibliographic metadata');
check(in_array('At least one viewable PDF or EPUB proof file is required.', $validator->validateBook($metadataOnlyBook), true), 'Live feed validation no longer requires a PDF/EPUB asset');
$metadataOnlyXml = $builder->build([$metadataOnlyBook], 'Test Publisher', null, null, new \DateTimeImmutable('2026-08-13 16:30:45', new \DateTimeZone('UTC')), false);
check(!str_contains($metadataOnlyXml, '<SalesRights>') && !str_contains($metadataOnlyXml, '<ProductSupply>'), 'Metadata-only validation ONIX unexpectedly contains rights or supply data');

$badIsbn10 = clone $book;
$badIsbn10->isbn10 = '0306406153';
check(in_array('ISBN-10 is invalid.', $validator->validateBook($badIsbn10), true), 'Invalid ISBN-10 was accepted');

$mismatchedIsbn10 = clone $book;
$mismatchedIsbn10->isbn10 = '0131103628';
check(in_array('ISBN-10 does not correspond to the ISBN-13 product identifier.', $validator->validateBook($mismatchedIsbn10), true), 'Mismatched ISBN-10 and ISBN-13 were accepted');

$editedVolume = clone $book;
$editedVolume->contributors = [['role' => 'B01', 'roles' => ['B01'], 'name' => 'Volume Editor', 'orcid' => null]];
check(in_array('At least one contributor with ContributorRole A01 is required for Google Play Books.', $validator->validateBook($editedVolume), true), 'Validator accepted an editor-only Google Play book without an A01 author');
$editedVolumeLegacyMultiRole = clone $book;
$editedVolumeLegacyMultiRole->contributors = [['role' => 'B01', 'roles' => ['B01', 'A01'], 'name' => 'Volume Editor', 'orcid' => null]];
check(in_array('Every contributor must contain exactly one three-character ONIX ContributorRole for Google Play Books.', $validator->validateBook($editedVolumeLegacyMultiRole), true), 'Google single ContributorRole profile was not enforced');
$editedXml = $builder->build([$editedVolumeLegacyMultiRole], 'Test Publisher');
check(substr_count($editedXml, '<ContributorRole>B01</ContributorRole>') === 1 && !str_contains($editedXml, '<ContributorRole>A01</ContributorRole>'), 'Builder did not collapse legacy multi-role data to the primary role');
$badContributorRole = clone $book;
$badContributorRole->contributors = [['role' => 'EDITOR', 'roles' => ['EDITOR'], 'name' => 'Volume Editor', 'orcid' => null]];
check(in_array('Every contributor role must be a three-character ONIX ContributorRole.', $validator->validateBook($badContributorRole), true), 'Malformed ONIX ContributorRole was accepted');

$badOrcid = clone $book;
$badOrcid->contributors[0]['orcid'] = '0000-0002-1825-0098';
check(in_array('Contributor ORCID is invalid.', $validator->validateBook($badOrcid), true), 'Invalid ORCID checksum was accepted');
$badOrcidXml = $builder->build([$badOrcid], 'Test Publisher');
check(!str_contains($badOrcidXml, '<NameIdentifier>'), 'Builder emitted an incomplete ONIX NameIdentifier for an invalid ORCID');

$coverOnly = clone $book;
$coverOnly->assets = [[
    'kind' => 'cover',
    'fileId' => 0,
    'formatId' => 0,
    'path' => 'test/cover.jpg',
    'mime' => 'image/jpeg',
    'extension' => 'jpg',
    'size' => 50,
    'modified' => 1002,
    'filename' => $canonical . '_frontcover.jpg',
]];
check(in_array('At least one viewable PDF or EPUB proof file is required.', $validator->validateBook($coverOnly), true), 'Cover-only metadata was accepted as a complete Google Books product');

$pngCover = clone $book;
$pngCover->assets[] = [
    'kind' => 'cover',
    'fileId' => 0,
    'formatId' => 0,
    'path' => 'test/cover.png',
    'mime' => 'image/png',
    'extension' => 'png',
    'size' => 50,
    'modified' => 1002,
    'filename' => $canonical . '_frontcover.png',
];
check($validator->validateBook($pngCover) === [], 'Validator rejected a canonical PNG cover asset');
$badPngMime = clone $pngCover;
$badPngMime->assets[array_key_last($badPngMime->assets)]['mime'] = 'image/jpeg';
check(in_array('Cover asset MIME type does not match its filename extension.', $validator->validateBook($badPngMime), true), 'PNG cover MIME mismatch was accepted');

$badAssetExtension = clone $book;
$badAssetExtension->assets[0]['extension'] = 'docx';
$badAssetExtension->assets[0]['filename'] = $canonical . '.docx';
check(in_array('Content assets must be PDF or EPUB files.', $validator->validateBook($badAssetExtension), true), 'Unsupported content asset extension was accepted');

$badAssetFilename = clone $book;
$badAssetFilename->assets[0]['filename'] = 'book.pdf';
check(in_array('Content asset filenames must use the canonical ISBN-13 followed by .pdf or .epub.', $validator->validateBook($badAssetFilename), true), 'Non-canonical Google content filename was accepted');

$duplicateAsset = clone $book;
$duplicateAsset->assets[1]['filename'] = $duplicateAsset->assets[0]['filename'];
check(in_array('Feed asset filenames must be unique within each ISBN product.', $validator->validateBook($duplicateAsset), true), 'Duplicate feed filenames were accepted');

$emptyAsset = clone $book;
$emptyAsset->assets[0]['size'] = 0;
check(in_array('Every feed asset must have a positive file size.', $validator->validateBook($emptyAsset), true), 'Zero-byte feed asset was accepted');

check($validator->validateXml($xml) === [], 'ONIX validator rejected generated XML');
$truncatedXml = substr($xml, 0, strrpos($xml, '</Product>'));
check(in_array('Generated ONIX is incomplete: closing ONIXMessage tag is missing.', $validator->validateXml($truncatedXml), true), 'Truncated ONIX document was not rejected before delivery');
$validatorSource = file_get_contents(dirname(__DIR__) . '/classes/Onix/GoogleOnixValidator.php');
check(str_contains($validatorSource, 'schemaValidate') && str_contains($validatorSource, 'ONIX_BookProduct_3.0_reference.xsd'), 'Runtime validation does not use the ONIX XSD bundled with OMP when available');
check(str_contains($xml, '<RecordReference>9780306406157</RecordReference>'), 'ONIX RecordReference is not stable ISBN-13');
check(str_contains($xml, '<ProductIDType>15</ProductIDType>'), 'ONIX ISBN-13 product identifier is missing');
check(str_contains($xml, '<ProductForm>EA</ProductForm>'), 'ONIX digital product form EA is missing');
check(str_contains($xml, '<ProductFormDetail>E101</ProductFormDetail>') && str_contains($xml, '<ProductFormDetail>E107</ProductFormDetail>'), 'ONIX EPUB/PDF product details are incomplete');
check(str_contains($xml, '<CollectionIDType>02</CollectionIDType>') && str_contains($xml, '<IDValue>20493630</IDValue>'), 'ONIX normalized series ISSN is missing');
check(str_contains($xml, '<SentDateTime>20260813T163045Z</SentDateTime>'), 'ONIX SentDateTime does not include precise UTC time');
$enriched = clone $book;
$enriched->subjects = [
    ['scheme' => '10', 'code' => 'SOC000000', 'heading' => null],
];
$enriched->extents = [['type' => '00', 'value' => '240', 'unit' => '03']];
$enriched->relatedProducts = [['relationCode' => '06', 'isbn13' => '9780131103627']];
$enrichedXml = $builder->build([$enriched], 'Test Publisher');
check(str_contains($enrichedXml, '<SubjectSchemeIdentifier>10</SubjectSchemeIdentifier>') && str_contains($enrichedXml, '<SubjectCode>SOC000000</SubjectCode>'), 'BISAC Subject enrichment is missing');
check(!str_contains($enrichedXml, '<SubjectSchemeIdentifier>20</SubjectSchemeIdentifier>') && !str_contains($enrichedXml, '<SubjectHeadingText>culture; education</SubjectHeadingText>'), 'Unsupported free-text Subject enrichment was emitted');
check(str_contains($enrichedXml, '<ExtentType>00</ExtentType>') && str_contains($enrichedXml, '<ExtentValue>240</ExtentValue>') && str_contains($enrichedXml, '<ExtentUnit>03</ExtentUnit>'), 'Page-count Extent enrichment is missing');
check(str_contains($enrichedXml, '<RelatedMaterial>') && str_contains($enrichedXml, '<ProductRelationCode>06</ProductRelationCode>') && str_contains($enrichedXml, '<IDValue>9780131103627</IDValue>'), 'RelatedProduct alternative-format ISBN is missing');
check($validator->validateMetadataBook($enriched) === [], 'Validator rejected valid optional ONIX enrichments');
check($validator->validateXml($enrichedXml) === [], 'XSD/runtime validator rejected enriched ONIX ordering or structure');
check(str_contains($xml, '<SalesRightsType>02</SalesRightsType>') && str_contains($xml, '<RegionsIncluded>WORLD</RegionsIncluded>'), 'Google rights ONIX is missing SalesRights territory');
check(str_contains($xml, '<UnpricedItemType>01</UnpricedItemType>') && !str_contains($xml, '<PriceAmount>0'), 'Free book ONIX pricing is not Google-compatible');
check((bool) preg_match('/<SupplyDetail>.*?<UnpricedItemType>01<\/UnpricedItemType>.*?<\/SupplyDetail>/s', $xml), 'Google free-book UnpricedItemType is not a direct SupplyDetail child');
check(!preg_match('/<([A-Za-z][A-Za-z0-9]*)(?:\s[^>]*)?><\/\1>/', $xml), 'Generated ONIX contains empty elements');
check(str_contains($xml, '<IDValue>0000-0002-1825-0097</IDValue>'), 'ORCID URL was not normalized to ONIX IDValue');

$mapper = new OmpBookMapper();
$cleanText = new ReflectionMethod($mapper, 'cleanText');
$cleaned = $cleanText->invoke($mapper, '<p>Research &amp; Society</p><p>Second line</p>');
check($cleaned === 'Research & Society Second line', 'OMP HTML/entity text normalization failed');
$entityBook = clone $book;
$entityBook->title = $cleaned;
$entityXml = $builder->build([$entityBook], 'Publisher & Society');
check(str_contains($entityXml, '<TitleText>Research &amp; Society Second line</TitleText>'), 'ONIX XML did not escape cleaned ampersands exactly once');
check(!str_contains($entityXml, '&amp;amp;'), 'ONIX XML double-escaped an HTML entity');
$promoteOrganizers = new ReflectionMethod($mapper, 'promoteOrganizersWhenAuthorMissing');
$organizedContributors = $promoteOrganizers->invoke($mapper, [
    ['role' => 'B01', 'roles' => ['B01'], 'name' => 'Organizer One', 'orcid' => null],
    ['role' => 'B21', 'roles' => ['B21'], 'name' => 'Organizer Two', 'orcid' => null],
]);
check(array_column($organizedContributors, 'role') === ['A01', 'A01'], 'OMP mapper did not promote every organizer when no author exists');
$mixedContributors = $promoteOrganizers->invoke($mapper, [
    ['role' => 'A01', 'roles' => ['A01'], 'name' => 'Real Author', 'orcid' => null],
    ['role' => 'B01', 'roles' => ['B01'], 'name' => 'Volume Organizer', 'orcid' => null],
]);
check(array_column($mixedContributors, 'role') === ['A01', 'B01'], 'OMP mapper changed organizer roles even though an author already exists');
$translatorOnly = $promoteOrganizers->invoke($mapper, [
    ['role' => 'B06', 'roles' => ['B06'], 'name' => 'Translator Only', 'orcid' => null],
]);
check(array_column($translatorOnly, 'role') === ['B06'], 'OMP mapper promoted a non-organizer contributor to A01');

$localizedData = new ReflectionMethod($mapper, 'localizedData');
$localizedObject = new class {
    public function getData(string $field, ?string $locale = null): mixed
    {
        if ($locale === 'pt_BR') {
            return ['title' => 'Título em português', 'abstract' => 'Resumo em português'][$field] ?? null;
        }
        return null;
    }

    public function getLocalizedData(string $field): mixed
    {
        return ['title' => 'Wrong UI-locale title', 'abstract' => 'Wrong UI-locale abstract'][$field] ?? null;
    }
};
check($localizedData->invoke($mapper, $localizedObject, 'title', 'pt_BR') === 'Título em português', 'Mapper ignored the publication locale for multilingual metadata');

$fullXml = $builder->build([$book], 'Test Publisher', null, null, null, false);
check(!str_contains($fullXml, '<ProductSupply>'), 'Full ONIX feed must be metadata-only');
check(!str_contains($fullXml, '<SalesRights>'), 'Full ONIX feed must not contain the rights profile');
$rightsXml = $builder->build([$book], 'Test Publisher', null, null, null, true);
check(str_contains($rightsXml, '<ProductSupply>') && str_contains($rightsXml, '<UnpricedItemType>01</UnpricedItemType>'), 'Rights ONIX feed must carry supply/free-price metadata');

$withoutIssn = clone $book;
$withoutIssn->seriesIssn = null;
$withoutIssnXml = $builder->build([$withoutIssn], 'Test Publisher');
check(str_contains($withoutIssnXml, '<Collection>'), 'Series title was dropped when no ISSN was available');
check(
    str_contains($withoutIssnXml, '<CollectionIDType>01</CollectionIDType>') &&
    str_contains($withoutIssnXml, '<IDTypeName>Publisher Series ID</IDTypeName>') &&
    str_contains($withoutIssnXml, '<IDValue>OMP1S77</IDValue>'),
    'Series without ISSN did not receive a stable proprietary CollectionIdentifier',
);
check($validator->validateBook($withoutIssn) === [], 'Validator rejected a series with a stable proprietary identifier');

$seriesWithoutIdentifier = clone $book;
$seriesWithoutIdentifier->seriesIssn = null;
$seriesWithoutIdentifier->seriesIdentifier = null;
check(in_array('Series identifier is required when a series title is supplied.', $validator->validateBook($seriesWithoutIdentifier), true), 'Series title without any identifier was accepted');

$dottedIssn = clone $book;
$dottedIssn->seriesIssn = '2049.3630';
$dottedIssnXml = $builder->build([$dottedIssn], 'Test Publisher');
check(str_contains($dottedIssnXml, '<IDValue>20493630</IDValue>'), 'Builder did not canonicalize a punctuated series ISSN');
check($validator->validateBook($dottedIssn) === [], 'Validator rejected a valid punctuated series ISSN');

$issnWithoutSeries = clone $book;
$issnWithoutSeries->seriesTitle = null;
check(in_array('Series title is required when a series ISSN is supplied.', $validator->validateBook($issnWithoutSeries), true), 'Series ISSN without a series title was accepted');

$paid = clone $book;
$paid->freeOfCharge = false;
$paid->prices = [['amount' => '12.50', 'currency' => 'EUR', 'territory' => 'ES']];
$paid->markets = [[
    'amount' => '12.50',
    'currency' => 'EUR',
    'priceType' => '01',
    'productAvailability' => '20',
    'countriesIncluded' => ['ES'],
    'regionsIncluded' => [],
    'countriesExcluded' => [],
    'regionsExcluded' => [],
]];
check($validator->validateRightsBook($paid) === [], 'Rights validator rejected a valid paid market');
$paidXml = $builder->build([$paid], 'Test Publisher');
check(str_contains($paidXml, '<PriceAmount>12.50</PriceAmount>') && str_contains($paidXml, '<CurrencyCode>EUR</CurrencyCode>'), 'Paid book ONIX price is missing');
check(str_contains($paidXml, '<CountriesIncluded>ES</CountriesIncluded>'), 'Paid market territory is missing');
check((bool) preg_match('/<Price>.*?<CountriesIncluded>ES<\/CountriesIncluded>.*?<\/Price>/s', $paidXml), 'Paid ONIX Price does not carry its own Google territory');
check(!str_contains($paidXml, '<UnpricedItemType>01</UnpricedItemType>'), 'Paid book was incorrectly marked free');

$contradictoryFree = clone $book;
$contradictoryFree->markets = $paid->markets;
check(in_array('Free books cannot contain a positive market price.', $validator->validateRightsBook($contradictoryFree), true), 'Free book with a positive market price was accepted');

$badCurrency = clone $paid;
$badCurrency->markets[0]['currency'] = '';
check(in_array('Every paid market must contain a three-letter currency code.', $validator->validateRightsBook($badCurrency), true), 'Paid market without currency was accepted');

$badPriceType = clone $paid;
$badPriceType->markets[0]['priceType'] = '99';
check(in_array('Every paid market must contain a Google-supported ONIX PriceType (01, 02, 03, 04, 41 or 42).', $validator->validateRightsBook($badPriceType), true), 'Unsupported Google ONIX PriceType was accepted');

$noRights = clone $book;
$noRights->salesRights = [];
check($validator->validateRightsBook($noRights) !== [], 'Rights feed accepted a book without SalesRights');

$badDate = clone $book;
$badDate->publicationDate = '20260231';
check(in_array('Publication date must be a real calendar date in YYYYMMDD format.', $validator->validateBook($badDate), true), 'Impossible calendar date was accepted');

$hash1 = $book->metadataFingerprint();
$hash2 = $book->contentFingerprint();
$changed = clone $book;
$changed->title = 'Changed Title';
check($changed->metadataFingerprint() !== $hash1, 'Metadata fingerprint did not detect a title change');
$changed = clone $book;
$changed->salesRights[0]['regionsIncluded'] = ['WORLD', 'ECZ'];
check($changed->metadataFingerprint() !== $hash1, 'Metadata fingerprint did not detect a rights change');
$changed = clone $book;
$changed->assets[0]['modified']++;
check($changed->contentFingerprint() !== $hash2, 'Content fingerprint did not detect an asset change');

$feedSource = file_get_contents(dirname(__DIR__) . '/classes/Feed/FeedHandler.php');
check(!str_contains($feedSource, "'href' => 'onix/'") && !str_contains($feedSource, "'href' => 'ebooks/'"), 'Feed directory still contains unsafe relative root links');
check(str_contains($feedSource, "\$request->getDispatcher()->url(") && str_contains($feedSource, 'GoogleBooksPlugin::FEED_PAGE'), 'Feed directory does not use an explicit OMP page-route URL');

$manifestSource = file_get_contents(dirname(__DIR__) . '/classes/Feed/FeedManifestService.php');
check(str_contains($manifestSource, "sync_status !== 'feed_available'"), 'Publisher feed exposes books before synchronization has prepared them');
check(str_contains($manifestSource, "new DateTimeImmutable('@' . \$revision)"), 'ONIX SentDateTime is not derived from the stable feed revision');
check(!str_contains($manifestSource, "new DateTimeImmutable('now'"), 'Repeated feed GETs would regenerate a different ONIX SentDateTime');

$pluginSource = file_get_contents(dirname(__DIR__) . '/GoogleBooksPlugin.php');
check(str_contains($pluginSource, '$templateMgr = $params[1];'), 'Public OMP template hook uses the wrong Smarty hook argument index');
check(str_contains($pluginSource, '(int) $record->publication_id !== (int) $publication->getId()'), 'Public Google Books identifier is not restricted to the current OMP publication version');
check(str_contains($pluginSource, "(string) (\$record->sync_status ?? '') === 'retired'"), 'Public Google Books identifier may expose a retired product');
check(!str_contains($pluginSource, "(string) \$record->sync_status !== 'feed_available'"), 'An exact existing Google record is hidden until publisher-feed synchronization');
check(str_contains($pluginSource, "(string) (\$record->discovery_status ?? '') === 'multiple_matches'"), 'Public Google Books identifier may expose an ambiguous Volume match');
check(str_contains($pluginSource, 'max(time(), $current + 1)'), 'Feed revision is not monotonic for repeated refreshes within one second');
check(str_contains($pluginSource, 'PublicationUnpublished::class') && str_contains($pluginSource, 'retireSubmission'), 'OMP unpublishing does not retire the book from the Google publisher feed state');
check(str_contains($pluginSource, "https://books.google.com/books?id="), 'Public identifier has no human-readable Google Books URL fallback when API info/preview links are absent');
check(str_contains($pluginSource, 'Application::ROUTE_PAGE') && str_contains($pluginSource, '$request->getDispatcher()->url('), 'Dashboard action does not use an explicit OMP page route');
check(!str_contains($pluginSource, "\$request->url(null, 'googlebooks')"), 'Dashboard action still uses Request::url() inside the plugin component router');

$repositorySource = file_get_contents(dirname(__DIR__) . '/classes/Repository/GoogleBooksStateRepository.php');
check(str_contains($repositorySource, 'if ($match->found)') && str_contains($repositorySource, 'Never erase a previously linked Google ID'), 'Discovery reconciliation may erase a previously linked Google Volume ID');
check(str_contains($repositorySource, '(int) ($record->publication_id ?? 0) !== $book->publicationId'), 'A new OMP publication version may be mistaken for an unchanged Google feed record');
check(str_contains($repositorySource, 'retireMissingProducts') && str_contains($repositorySource, "'sync_status' => 'retired'"), 'Stale ISBN products are not retired from the publisher feed');
check(str_contains($repositorySource, 'retireMissingSubmissions'), 'Catalog reconciliation does not retire records for unpublished submissions');
check(str_contains($repositorySource, 'max(time(), $previousEpoch + 1)'), 'Forced per-product feed timestamps are not monotonic within one second');
check(str_contains($repositorySource, 'insertOrIgnore'), 'Concurrent OMP metadata events may create duplicate state insert failures');
check(str_contains($repositorySource, "where('sync_status', '!=', 'retired')"), 'Retired records are still counted as active dashboard matches');
check(str_contains($repositorySource, "return gmdate('Y-m-d H:i:s')"), 'Plugin database timestamps are not stored consistently in UTC');
check(str_contains($repositorySource, "(string) (\$record->sync_status ?? '') !== 'feed_available'"), 'A retired/error product may not be reactivated when it becomes valid again');
check(str_contains($repositorySource, "where('discovery_status', 'linked')"), 'Linked catalog count includes stale or ambiguous Google IDs');

$syncSource = file_get_contents(dirname(__DIR__) . '/classes/Sync/GoogleBooksSyncService.php');
check(str_contains($syncSource, 'public function discoverSubmission') && str_contains($syncSource, 'public function syncSubmission') && substr_count($syncSource, 'new GoogleBooksApiClient') === 1, 'Publisher feed synchronization is not decoupled from Google Books API discovery credentials');
check(str_contains($syncSource, 'no valid Google collection code is configured for this book/imprint'), 'Synchronization does not reject books that cannot be routed to a Google collection code');
check(str_contains($syncSource, 'retireMissingProducts') && str_contains($syncSource, "'feedChanged'"), 'Submission synchronization does not reconcile stale ISBNs or signal feed changes');
check(str_contains($syncSource, 'retireMissingSubmissions'), 'Full catalog synchronization does not reconcile unpublished books');
check(substr_count($syncSource, "\$result['retryable']++") >= 2 && str_contains($syncSource, 'markDiscoveryError'), 'Transient or not-yet-indexed Google checks are not consistently marked retryable');
$apiSource = file_get_contents(dirname(__DIR__) . '/classes/Api/GoogleBooksApiClient.php');
check(str_contains($apiSource, 'global lookup') && str_contains($apiSource, '$withPartner && $this->partnerId'), 'Google discovery may be partner-restricted before checking the global catalogue');

$mapperSource = file_get_contents(dirname(__DIR__) . '/classes/Sync/OmpBookMapper.php');
check(str_contains($mapperSource, "method_exists(\$format, 'getIsAvailable')"), 'Mapper does not reject unavailable OMP publication formats');
check(str_contains($mapperSource, '$directSalesPrice === null'), 'Mapper may expose proof files that OMP does not make available in the public catalog');
check(substr_count($mapperSource, 'getDirectSalesPrice()') === 1, 'Mapper repeatedly calls an optional OMP direct-sales accessor instead of reusing one resolved value');
check(str_contains($mapperSource, 'assetsAreFree') && str_contains($mapperSource, "'directSalesPrice'"), 'Mapper does not infer free OMP books from zero-priced public proof files');
check(str_contains($mapperSource, "['getOnlineISSN', 'getPrintISSN']"), 'Mapper does not read OMP series ISSNs through their native accessors');
check(str_contains($mapperSource, "'OMP' . \$contextId . 'S' . (int) \$seriesId"), 'Mapper does not create a stable proprietary identifier for OMP series without ISSN');
check(str_contains($mapperSource, "method_exists(\$file, 'getChapterId')") && str_contains($mapperSource, 'if ($chapterId > 0)'), 'Mapper does not exclude chapter proof files from whole-book delivery');
check(str_contains($mapperSource, "['jpg', 'jpeg', 'png']") && str_contains($mapperSource, "'image/png'"), 'Mapper does not preserve supported PNG cover assets');
$validatorSourceContract = file_get_contents(dirname(__DIR__) . '/classes/Onix/GoogleOnixValidator.php');
check(str_contains($validatorSourceContract, "['jpg', 'png']") && str_contains($validatorSourceContract, "'image/png'"), 'Validator does not accept supported PNG cover assets');
check(str_contains($mapperSource, "localizedData(\$publication, 'title', \$language)"), 'Mapper does not read publication metadata in the publication locale');
check(str_contains($mapperSource, 'html_entity_decode') && str_contains($mapperSource, "ENT_QUOTES | ENT_HTML5"), 'Mapper does not decode OMP HTML entities before XML escaping');

$dashboardSource = file_get_contents(dirname(__DIR__) . '/classes/DashboardHandler.php');
check(str_contains($dashboardSource, "'googleApiKey' => ''"), 'Dashboard may render the stored Google API key');
check(str_contains($dashboardSource, "defaultWorldwideRightsForFree"), 'Worldwide-rights safety setting is missing');
check(!str_contains($dashboardSource, 'feedCredentialsRequiredForValidation'), 'Validation sample still incorrectly requires saved feed credentials');
check(str_contains($dashboardSource, 'operationFailure') && str_contains($dashboardSource, 'catch (Throwable $e)'), 'Dashboard operations are not protected from raw HTTP 500 exceptions');
check(str_contains($dashboardSource, "'googleBooksSaveApiUrl'") && str_contains($dashboardSource, "'googleBooksSaveFeedUrl'") && str_contains($dashboardSource, "'googleBooksDownloadValidationUrl'"), 'Dashboard does not generate explicit decoupled action URLs in the controller');
check(str_contains($mapperSource, '->fileExists($path)') && !str_contains($mapperSource, '->has($path)'), 'Explicit OMP 3.5 proof-file existence check is missing');
check(str_contains($manifestSource, 'validateMetadataBook($book)') && str_contains($manifestSource, 'false,'), 'Initial ONIX sample is not separated from the live rights/supply feed');

check(str_contains($dashboardSource, "CatalogDiscoveryJob::dispatch") && str_contains($dashboardSource, 'backgroundQueueConnection') && str_contains($dashboardSource, 'public function discover'), 'Dashboard does not provide a safely queued API-only Google discovery action');
check(str_contains($dashboardSource, "apiKeyRequired"), 'Dashboard verification does not require a configured Google Books API key');
check(str_contains($dashboardSource, "autoVerifyGoogle"), 'Automatic post-crawl verification setting is missing');
check(str_contains($dashboardSource, 'safeHttpUrl') && str_contains($dashboardSource, "['http', 'https']"), 'Dashboard does not sanitize stored Google links');
check(str_contains($dashboardSource, "https://books.google.com/books?id="), 'Dashboard has no human-readable Google Books URL fallback when API info/preview links are absent');

$verificationJobSource = file_get_contents(dirname(__DIR__) . '/classes/Jobs/BookVerificationJob.php');
check(str_contains($verificationJobSource, 'CHECKPOINTS_HOURS = [6, 24, 72, 168]'), 'Bounded automatic Google verification checkpoint schedule is missing');
check(str_contains($verificationJobSource, 'verifySubmission'), 'Automatic verification job does not use discovery-only verification');
check(!str_contains($verificationJobSource, "\$result['linked'] > 0 ||"), 'Finding one ISBN product incorrectly stops verification of other missing products');

check(BookVerificationJob::delayHoursForAttempt(1) === 6, 'Book verification first checkpoint delay is wrong');
check(BookVerificationJob::delayHoursForAttempt(2) === 18, 'Book verification 24-hour checkpoint delta is wrong');
check(BookVerificationJob::delayHoursForAttempt(3) === 48, 'Book verification 72-hour checkpoint delta is wrong');
check(BookVerificationJob::delayHoursForAttempt(4) === 96, 'Book verification 168-hour checkpoint delta is wrong');
check(CatalogVerifyJob::delayHoursForAttempt(1) === 6 && CatalogVerifyJob::delayHoursForAttempt(4) === 96, 'Catalog verification checkpoint deltas are wrong');

$submissionJobSource = file_get_contents(dirname(__DIR__) . '/classes/Jobs/SubmissionSyncJob.php');
check(str_contains($submissionJobSource, "\$result['feedChanged']"), 'Per-book synchronization does not bump the feed revision when a product is retired or removed after validation failure');
check(!str_contains($submissionJobSource, "\$result['failed'] === 0"), 'A warning on one ISBN product incorrectly suppresses verification of another missing product');
check(!str_contains($submissionJobSource, 'GoogleBooksApiClient') && str_contains($submissionJobSource, 'BookVerificationJob::dispatch'), 'Per-book feed synchronization is not decoupled from API discovery or post-crawl verification');
$catalogSyncJobSource = file_get_contents(dirname(__DIR__) . '/classes/Jobs/CatalogSyncJob.php');
$catalogVerifyJobSource = file_get_contents(dirname(__DIR__) . '/classes/Jobs/CatalogVerifyJob.php');
check(!str_contains($catalogSyncJobSource, 'GoogleBooksApiClient') && str_contains($catalogSyncJobSource, 'CatalogDiscoveryJob::dispatch'), 'Catalog feed synchronization is not decoupled from API discovery or post-crawl verification');
check(str_contains($verificationJobSource, "\$result['retryable'] === 0") && str_contains($catalogVerifyJobSource, 'CatalogDiscoveryJob::dispatch'), 'Compatibility verification jobs are not routed to the API-only discovery pipeline');

$discoveryJobSource = file_get_contents(dirname(__DIR__) . '/classes/Jobs/CatalogDiscoveryJob.php');
check(str_contains($discoveryJobSource, 'BATCH_SIZE = 10') && str_contains($discoveryJobSource, 'array_slice') && str_contains($discoveryJobSource, 'usleep(250000)'), 'Large-catalogue discovery is not conservatively batched/throttled.');
check(str_contains($mapperSource, 'mapDiscoverySubmission') && str_contains($mapperSource, 'Discovery is deliberately independent from feed eligibility'), 'Mapper lacks an API-only discovery path for historical books.');
check(str_contains($mapperSource, "DAORegistry::getDAO('PublicationFormatDAO')") && str_contains($mapperSource, 'getByPublicationId'), 'Mapper does not load OMP 3.5 publication formats from PublicationFormatDAO.');
check(str_contains($mapperSource, "'24' => 30") && str_contains($mapperSource, 'getFormatDiscoveryIsbns13'), 'Mapper does not recognize co-publisher ISBN-13 (ONIX code 24) during discovery.');
check(str_contains($pluginSource, 'SubmissionDiscoveryJob::dispatchAfterResponse') && str_contains($pluginSource, 'canAutoDiscover'), 'Published metadata changes do not trigger independent API discovery.');
check(str_contains($pluginSource, "'volumeId' => (string) \$record->google_volume_id"), 'Public book details do not receive the discovered Google Volume ID.');
$publicIdentifierTemplate = file_get_contents(dirname(__DIR__) . '/templates/publicIdentifier.tpl');
check(str_contains($publicIdentifierTemplate, 'Google Volume ID') && str_contains($publicIdentifierTemplate, 'isbn13'), 'Public Google Books identifier block does not display the discovered Volume ID and ISBN.');
check(str_contains($dashboardSource, 'persistApiSettings') && str_contains($dashboardSource, 'persistFeedSettings') && str_contains($dashboardSource, 'persistBehaviorSettings'), 'Legacy combined Save endpoint does not preserve all settings after an in-place upgrade.');

$migrationSource = file_get_contents(dirname(__DIR__) . '/classes/Migration/GoogleBooksSchemaMigration.php');
check(str_contains($migrationSource, "books_retired"), 'Catalog synchronization history does not record retired products');

$secretKey = 'test-app-key-google-books-0.1.2.2';
$encryptedSecret = SecretStore::encrypt("sftp-secret\nwith-lines", $secretKey);
check(SecretStore::isEncrypted($encryptedSecret), 'Reversible transport secret does not use the versioned encrypted format');
check($encryptedSecret !== "sftp-secret\nwith-lines", 'Transport secret was stored as plaintext');
check(SecretStore::decrypt($encryptedSecret, $secretKey) === "sftp-secret\nwith-lines", 'Transport secret encryption round-trip failed');
$tamperPos = strlen('gbsec:v1:') + 8;
$tampered = substr($encryptedSecret, 0, $tamperPos) . ($encryptedSecret[$tamperPos] === 'A' ? 'B' : 'A') . substr($encryptedSecret, $tamperPos + 1);
$tamperRejected = false;
try { SecretStore::decrypt($tampered, $secretKey); } catch (Throwable) { $tamperRejected = true; }
check($tamperRejected, 'Tampered encrypted transport secret was accepted');


$encryptedApiKey = SecretStore::encryptApiKey('AIza-test-key-not-real', $secretKey);
check(SecretStore::isApiKeyEncrypted($encryptedApiKey), 'Google Books API key does not use its versioned encrypted format');
check($encryptedApiKey !== 'AIza-test-key-not-real', 'Google Books API key was stored as plaintext');
check(SecretStore::decryptApiKey($encryptedApiKey, $secretKey) === 'AIza-test-key-not-real', 'Google Books API-key encryption round-trip failed');
$apiTamperPos = strlen('gbapi:v1:') + 8;
$apiTampered = substr($encryptedApiKey, 0, $apiTamperPos) . ($encryptedApiKey[$apiTamperPos] === 'A' ? 'B' : 'A') . substr($encryptedApiKey, $apiTamperPos + 1);
$apiTamperRejected = false;
try { SecretStore::decryptApiKey($apiTampered, $secretKey); } catch (Throwable) { $apiTamperRejected = true; }
check($apiTamperRejected, 'Tampered encrypted Google Books API key was accepted');
check(str_contains($pluginSource, 'getGoogleApiKey') && str_contains($pluginSource, 'setGoogleApiKey') && str_contains($pluginSource, "'googleApiKeyEncrypted'"), 'Plugin API-key encryption accessors are missing');
check(!str_contains($dashboardSource, "updateSetting(\$contextId, 'googleApiKey', \$newApiKey"), 'Dashboard still persists a new Google Books API key in plaintext');

$modes = DeliveryConfig::modes();
check($modes === ['http_pull', 'google_sftp', 'publisher_sftp', 'publisher_ftp', 'gcs', 'local_export'], 'Delivery mode registry does not expose the complete supported transport set');
$caps = TransportCapabilities::evaluate(true, ['http', 'https', 'sftp', 'ftp', 'ftps'], true, true, true, true);
check(($caps['googleSftpDropbox'] ?? false) && ($caps['publisherSftp'] ?? false), 'SFTP capability detection failed');
check(($caps['publisherFtp'] ?? false) && ($caps['publisherFtps'] ?? false), 'FTP/FTPS capability detection failed');
check(($caps['gcs'] ?? false) && ($caps['localExport'] ?? false), 'GCS/local-export capability detection failed');
$capsMinimal = TransportCapabilities::evaluate(false, [], false, false, false, false);
check(($capsMinimal['httpPull'] ?? false) && ($capsMinimal['localExport'] ?? false), 'Transport capability fallback lost HTTP pull or local export');
check(!($capsMinimal['googleSftpDropbox'] ?? true) && !($capsMinimal['gcs'] ?? true), 'Unavailable SFTP/GCS transports were reported as available');

$deliveryManagerSource = file_get_contents(dirname(__DIR__) . '/classes/Delivery/DeliveryManager.php');
$deliveryManifestSource = file_get_contents(dirname(__DIR__) . '/classes/Delivery/DeliveryManifestService.php');
check(str_contains($deliveryManagerSource, 'DeliveryConfig::GOOGLE_SFTP') && str_contains($deliveryManagerSource, 'DeliveryConfig::GCS'), 'Delivery manager is missing Google SFTP Dropbox or GCS transport routing');
check(str_contains($deliveryManagerSource, "'skipped'") && str_contains($deliveryManagerSource, 'hash_equals'), 'Incremental delivery fingerprint skipping is missing');
check(str_contains($deliveryManifestSource, "'onix/' . \$code . '-full/'") && str_contains($deliveryManifestSource, "'ebooks/' . \$code . '/'"), 'Delivery manifest does not preserve the Google automated-fetch directory contract');
check(str_contains($dashboardSource, 'persistTransportAuthSettings') && str_contains($dashboardSource, 'SecretStore::encrypt'), 'Dashboard does not persist outbound transport credentials through encrypted storage');
check(str_contains($dashboardSource, 'googleBooksDeliveryCapabilities') && str_contains($dashboardSource, 'googleBooksSaveDeliveryUrl'), 'Dashboard delivery capability/configuration wiring is missing');
check(str_contains($submissionJobSource, 'DeliveryJob::dispatch') && str_contains($catalogSyncJobSource, 'DeliveryJob::dispatch'), 'Feed changes do not enqueue push delivery for non-HTTP transports');

if ($failures) {
    fwrite(STDERR, "FAILED " . count($failures) . " of {$tests} assertions\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "OK {$tests} assertions\n";
