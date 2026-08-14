<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Onix;

use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;

final class GoogleOnixValidator
{
    /**
     * Validate bibliographic metadata required by Google's ONIX ingestion
     * profile. This intentionally does not require an EPUB/PDF asset or sales
     * rights so it can also be used for Google's one-time ONIX format sample.
     *
     * @return string[]
     */
    public function validateMetadataBook(BookMetadata $book): array
    {
        $errors = [];
        if (!IdentifierNormalizer::isValidIsbn13($book->isbn13)) {
            $errors[] = 'A valid ISBN-13 is required.';
        }
        if ($book->isbn10 !== null) {
            $isbn10 = IdentifierNormalizer::normalizeIsbn($book->isbn10);
            if ($isbn10 === null || strlen($isbn10) !== 10) {
                $errors[] = 'ISBN-10 is invalid.';
            } elseif (IdentifierNormalizer::isbn10To13($isbn10) !== $book->isbn13) {
                $errors[] = 'ISBN-10 does not correspond to the ISBN-13 product identifier.';
            }
        }
        if (trim($book->title) === '') {
            $errors[] = 'Title is required.';
        }
        if (trim($book->publisher) === '') {
            $errors[] = 'Publisher is required.';
        }
        if (!$this->isValidDate($book->publicationDate)) {
            $errors[] = 'Publication date must be a real calendar date in YYYYMMDD format.';
        }
        if ($book->contributors === []) {
            $errors[] = 'At least one contributor is required.';
        }
        $hasPrimaryAuthorRole = false;
        foreach ($book->contributors as $contributor) {
            if (trim((string) ($contributor['name'] ?? '')) === '') {
                $errors[] = 'Contributor names cannot be empty.';
            }
            $roles = $this->contributorRoles($contributor);
            if ($roles === []) {
                $errors[] = 'Every contributor must contain at least one three-character ONIX ContributorRole.';
            }
            foreach ($roles as $role) {
                if (!preg_match('/^[A-Z][0-9]{2}$/', $role)) {
                    $errors[] = 'Every contributor role must be a three-character ONIX ContributorRole.';
                }
                if ($role === 'A01') {
                    $hasPrimaryAuthorRole = true;
                }
            }
            if (!empty($contributor['orcid']) && IdentifierNormalizer::normalizeOrcid((string) $contributor['orcid']) === null) {
                $errors[] = 'Contributor ORCID is invalid.';
            }
        }
        if ($book->contributors !== [] && !$hasPrimaryAuthorRole) {
            $errors[] = 'Google Books requires at least one A01 contributor role for every book.';
        }

        if ($book->seriesIssn !== null && IdentifierNormalizer::normalizeIssn($book->seriesIssn) === null) {
            $errors[] = 'Series ISSN is invalid after normalization.';
        }
        if ($book->seriesIssn !== null && trim((string) $book->seriesTitle) === '') {
            $errors[] = 'Series title is required when a series ISSN is supplied.';
        }
        if ($book->seriesIdentifier !== null && !$this->isValidSeriesIdentifier($book->seriesIdentifier)) {
            $errors[] = 'Series proprietary identifier is invalid.';
        }
        if (trim((string) $book->seriesTitle) !== '' && $book->seriesIssn === null && $book->seriesIdentifier === null) {
            $errors[] = 'Series identifier is required when a series title is supplied.';
        }
        if ($book->seriesIdentifier !== null && trim((string) $book->seriesTitle) === '') {
            $errors[] = 'Series title is required when a proprietary series identifier is supplied.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Validate a product that will be exposed through the live Google feed.
     * Live feed products must also expose at least one valid whole-book
     * PDF/EPUB asset.
     *
     * @return string[]
     */
    public function validateBook(BookMetadata $book): array
    {
        $errors = $this->validateMetadataBook($book);
        $contentAssets = 0;
        $filenames = [];
        foreach ($book->assets as $asset) {
            $kind = strtolower(trim((string) ($asset['kind'] ?? '')));
            $extension = strtolower(trim((string) ($asset['extension'] ?? '')));
            $filename = trim((string) ($asset['filename'] ?? ''));
            $path = trim((string) ($asset['path'] ?? ''));
            $size = (int) ($asset['size'] ?? 0);

            if ($filename === '') {
                $errors[] = 'Every feed asset must have a filename.';
            } elseif (isset($filenames[$filename])) {
                $errors[] = 'Feed asset filenames must be unique within each ISBN product.';
            } else {
                $filenames[$filename] = true;
            }
            if ($path === '') {
                $errors[] = 'Every feed asset must reference a source path.';
            }
            if ($size <= 0) {
                $errors[] = 'Every feed asset must have a positive file size.';
            }

            if ($kind === 'content') {
                $contentAssets++;
                if (!in_array($extension, ['pdf', 'epub'], true)) {
                    $errors[] = 'Content assets must be PDF or EPUB files.';
                } elseif ($filename !== $book->isbn13 . '.' . $extension) {
                    $errors[] = 'Content asset filenames must use the canonical ISBN-13 followed by .pdf or .epub.';
                }
                if ((int) ($asset['fileId'] ?? 0) <= 0 || (int) ($asset['formatId'] ?? 0) <= 0) {
                    $errors[] = 'Content assets must reference an OMP file and publication format.';
                }
            } elseif ($kind === 'cover') {
                if (
                    !in_array($extension, ['jpg', 'png'], true) ||
                    $filename !== $book->isbn13 . '_frontcover.' . $extension
                ) {
                    $errors[] = 'Cover assets must use the canonical ISBN-13 followed by _frontcover.jpg or _frontcover.png.';
                }
                $mime = strtolower(trim((string) ($asset['mime'] ?? '')));
                $expectedMime = $extension === 'png' ? 'image/png' : 'image/jpeg';
                if ($mime !== '' && $mime !== $expectedMime) {
                    $errors[] = 'Cover asset MIME type does not match its filename extension.';
                }
            } else {
                $errors[] = 'Every feed asset must be identified as content or cover.';
            }
        }
        if ($contentAssets === 0) {
            $errors[] = 'At least one viewable PDF or EPUB proof file is required.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Validate fields that Google needs in the rights/sales-settings feed.
     *
     * @return string[]
     */
    public function validateRightsBook(BookMetadata $book): array
    {
        $errors = $this->validateBook($book);

        if ($book->salesRights === []) {
            $errors[] = 'At least one SalesRights territory is required for the Google rights feed.';
        }

        $hasSaleTerritory = false;
        foreach ($book->salesRights as $right) {
            $type = trim((string) ($right['type'] ?? ''));
            if (!preg_match('/^\d{2}$/', $type)) {
                $errors[] = 'Every SalesRights entry must contain a two-digit ONIX SalesRightsType.';
            }
            if (!$this->hasIncludedTerritory($right)) {
                $errors[] = 'Every SalesRights entry must include CountriesIncluded or RegionsIncluded.';
            }
            if (in_array($type, ['01', '02'], true) && $this->hasIncludedTerritory($right)) {
                $hasSaleTerritory = true;
            }
        }
        if (!$hasSaleTerritory) {
            $errors[] = 'At least one for-sale SalesRights entry (01 or 02) is required.';
        }

        if ($book->freeOfCharge) {
            foreach (array_merge($book->prices, $book->markets) as $price) {
                $amount = trim((string) ($price['amount'] ?? ''));
                if ($amount !== '' && is_numeric($amount) && (float) $amount > 0) {
                    $errors[] = 'Free books cannot contain a positive market price.';
                    break;
                }
            }
        } else {
            if ($book->markets === []) {
                $errors[] = 'Paid books require at least one OMP market with price, currency and territory.';
            }
            $hasPaidMarket = false;
            foreach ($book->markets as $market) {
                $amount = trim((string) ($market['amount'] ?? ''));
                if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
                    continue;
                }
                $hasPaidMarket = true;
                if (!preg_match('/^[A-Z]{3}$/', (string) ($market['currency'] ?? ''))) {
                    $errors[] = 'Every paid market must contain a three-letter currency code.';
                }
                if (!in_array((string) ($market['priceType'] ?? ''), ['01', '02', '03', '04', '41', '42'], true)) {
                    $errors[] = 'Every paid market must contain a Google-supported ONIX PriceType (01, 02, 03, 04, 41 or 42).';
                }
                if (!$this->hasIncludedTerritory($market)) {
                    $errors[] = 'Every paid market must include CountriesIncluded or RegionsIncluded.';
                }
            }
            if (!$hasPaidMarket) {
                $errors[] = 'Paid books require at least one positive market price.';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $contributor @return string[] */
    private function contributorRoles(array $contributor): array
    {
        $rawRoles = $contributor['roles'] ?? [$contributor['role'] ?? ''];
        if (!is_array($rawRoles)) {
            $rawRoles = [$rawRoles];
        }
        $roles = [];
        foreach ($rawRoles as $role) {
            $role = strtoupper(trim((string) $role));
            if ($role !== '' && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }


    private function isValidSeriesIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[A-Z0-9._:-]{1,100}$/', strtoupper(trim($identifier)));
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{8}$/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false) {
            return false;
        }
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }
        return $date->format('Ymd') === $value;
    }

    /** @param array<string,mixed> $row */
    private function hasIncludedTerritory(array $row): bool
    {
        return !empty($row['countriesIncluded']) || !empty($row['regionsIncluded']);
    }

    /** @return string[] */
    public function validateXml(string $xml): array
    {
        $errors = [];
        foreach (['<ONIXMessage ', '<Header>', '<SentDateTime>', '<Product>', '<RecordReference>', '<ProductIDType>15</ProductIDType>', '<ProductForm>EA</ProductForm>', '<PublishingDateRole>01</PublishingDateRole>'] as $required) {
            if (!str_contains($xml, $required)) {
                $errors[] = 'Missing required ONIX element: ' . trim($required, '<>');
            }
        }
        if (preg_match('/<([A-Za-z][A-Za-z0-9]*)(?:\s[^>]*)?><\/\1>/', $xml)) {
            $errors[] = 'Generated ONIX contains an empty element.';
        }
        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                if (!$dom->loadXML($xml, LIBXML_NONET)) {
                    foreach (libxml_get_errors() as $error) {
                        $errors[] = trim($error->message);
                    }
                } else {
                    $xsdPath = $this->ompOnixSchemaPath();
                    if ($xsdPath !== null && !$dom->schemaValidate($xsdPath)) {
                        foreach (libxml_get_errors() as $error) {
                            $errors[] = 'ONIX XSD: ' . trim($error->message);
                        }
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        return array_values(array_unique(array_filter($errors)));
    }

    /**
     * Use the ONIX 3.0 reference schema bundled with OMP when available.
     * The plugin remains installable without copying or redistributing the
     * EDItEUR schema files.
     */
    private function ompOnixSchemaPath(): ?string
    {
        if (!class_exists(\PKP\core\Core::class)) {
            return null;
        }
        $path = rtrim(\PKP\core\Core::getBaseDir(), DIRECTORY_SEPARATOR)
            . '/plugins/importexport/onix30/ONIX_BookProduct_3.0_reference.xsd';
        return is_file($path) ? $path : null;
    }
}
