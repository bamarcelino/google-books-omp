<?php

declare(strict_types=1);

/**
 * @file classes/Util/IdentifierNormalizer.php
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Util;

final class IdentifierNormalizer
{
    public static function compact(string $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9X]/i', '', trim($value)));
    }

    public static function normalizeIsbn(string $value): ?string
    {
        $compact = self::compactIsbn($value);
        if (strlen($compact) === 10 && self::isValidIsbn10($compact)) {
            return $compact;
        }
        if (strlen($compact) === 13 && self::isValidIsbn13($compact)) {
            return $compact;
        }
        return null;
    }

    /**
     * Return all equivalent ISBN forms that can be used to compare OMP and Google Books.
     * Punctuation, spaces, dots and hyphens are ignored.
     *
     * @return string[]
     */
    public static function isbnEquivalents(string $value): array
    {
        $isbn = self::normalizeIsbn($value);
        if ($isbn === null) {
            return [];
        }

        $values = [$isbn];
        if (strlen($isbn) === 10) {
            $values[] = self::isbn10To13($isbn);
        } elseif (str_starts_with($isbn, '978')) {
            $isbn10 = self::isbn13To10($isbn);
            if ($isbn10 !== null) {
                $values[] = $isbn10;
            }
        }

        return array_values(array_unique(array_filter($values)));
    }

    public static function preferredIsbn13(string $value): ?string
    {
        $isbn = self::normalizeIsbn($value);
        if ($isbn === null) {
            return null;
        }
        if (strlen($isbn) === 13) {
            return $isbn;
        }
        return self::isbn10To13($isbn);
    }

    public static function normalizeIssn(string $value): ?string
    {
        $compact = self::compactIssn($value);
        if (strlen($compact) !== 8 || !self::isValidIssn($compact)) {
            return null;
        }
        return $compact;
    }

    public static function formatIssn(string $value): ?string
    {
        $issn = self::normalizeIssn($value);
        return $issn === null ? null : substr($issn, 0, 4) . '-' . substr($issn, 4);
    }

    public static function normalizeOrcid(string $value): ?string
    {
        $value = preg_replace('#^https?://orcid\.org/#i', '', trim($value)) ?? trim($value);
        $compact = strtoupper((string) preg_replace('/[^0-9X]/i', '', $value));
        if (!preg_match('/^[0-9]{15}[0-9X]$/', $compact)) {
            return null;
        }

        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            $total = ($total + (int) $compact[$i]) * 2;
        }
        $result = (12 - ($total % 11)) % 11;
        $expected = $result === 10 ? 'X' : (string) $result;
        return $compact[15] === $expected ? $compact : null;
    }

    public static function formatOrcid(string $value): ?string
    {
        $orcid = self::normalizeOrcid($value);
        if ($orcid === null) {
            return null;
        }
        return implode('-', str_split($orcid, 4));
    }

    public static function isValidIsbn10(string $isbn): bool
    {
        if (!preg_match('/^[0-9]{9}[0-9X]$/', $isbn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = ($i === 9 && $isbn[$i] === 'X') ? 10 : (int) $isbn[$i];
            $sum += (10 - $i) * $digit;
        }
        return $sum % 11 === 0;
    }

    public static function isValidIsbn13(string $isbn): bool
    {
        if (!preg_match('/^(?:978|979)[0-9]{10}$/', $isbn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;
        return $check === (int) $isbn[12];
    }

    public static function isValidIssn(string $issn): bool
    {
        if (!preg_match('/^[0-9]{7}[0-9X]$/', $issn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (8 - $i) * (int) $issn[$i];
        }
        $remainder = 11 - ($sum % 11);
        $expected = $remainder === 10 ? 'X' : ($remainder === 11 ? '0' : (string) $remainder);
        return $issn[7] === $expected;
    }

    public static function isbn10To13(string $isbn10): ?string
    {
        $isbn10 = self::normalizeIsbn($isbn10) ?? '';
        if (strlen($isbn10) !== 10) {
            return null;
        }
        $base = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $base[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return $base . ((10 - ($sum % 10)) % 10);
    }

    public static function isbn13To10(string $isbn13): ?string
    {
        $isbn13 = self::normalizeIsbn($isbn13) ?? '';
        if (strlen($isbn13) !== 13 || !str_starts_with($isbn13, '978')) {
            return null;
        }
        $base = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (10 - $i) * (int) $base[$i];
        }
        $remainder = 11 - ($sum % 11);
        $check = $remainder === 10 ? 'X' : ($remainder === 11 ? '0' : (string) $remainder);
        return $base . $check;
    }

    /**
     * Remove common human-readable ISBN labels before compacting the value.
     * Without this step a label such as "ISBN-13" would contribute the digits
     * "13" and make an otherwise valid identifier appear invalid.
     */
    private static function compactIsbn(string $value): string
    {
        $value = preg_replace('/\bISBN\s*(?:-\s*)?(?:10|13)\s*:?\s*/i', '', trim($value)) ?? trim($value);
        return self::compact($value);
    }

    /**
     * Remove ISSN/ISSN-L labels, then ignore punctuation and spacing. The
     * canonical comparison value remains the eight-character ISSN itself.
     */
    private static function compactIssn(string $value): string
    {
        $value = preg_replace('/\b(?:E|P)?ISSN(?:\s*(?:-\s*)?L)?\s*:?\s*/i', '', trim($value)) ?? trim($value);
        return self::compact($value);
    }
}
