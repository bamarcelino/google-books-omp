<?php

declare(strict_types=1);

/**
 * Source-backed ONIX enrichment for Google Books / Google Play Books.
 *
 * This service never invents classifications, pagination, summaries or ISBNs.
 * Optional ONIX composites are emitted only when the corresponding metadata is
 * present in OMP (or in an explicitly named custom metadata field).
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Onix;

use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;
use PKP\db\DAORegistry;
use Throwable;

final class OnixEnrichmentService
{
    public function enrich(BookMetadata $book, object $submission, object $context): BookMetadata
    {
        $publication = method_exists($submission, 'getCurrentPublication')
            ? $submission->getCurrentPublication()
            : null;
        if (!$publication) {
            return $book;
        }

        $locale = (string) ($publication->getData('locale') ?: $context->getPrimaryLocale());
        if (!$book->description) {
            $book->description = $this->firstLocalizedText($publication, 'abstract', $locale);
        }

        $book->subjects = $this->subjects($publication, $locale);
        [$format, $formats] = $this->formatForBook($publication, $context, $book->isbn13);
        $book->extents = $format ? $this->extents($format) : [];
        $book->relatedProducts = $this->relatedProducts($formats, $book->isbn13);

        return $book;
    }

    /** @return array<int,array{scheme:string,code:?string,heading:?string}> */
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

    /** @return array<int,array{type:string,value:string,unit:string}> */
    private function extents(object $format): array
    {
        $extents = [];

        // Use only explicit page metadata; do not guess a PDF page count.
        $pageCount = $this->positiveIntegerFromFormat($format, [
            'pageCount', 'pages', 'extentPages', 'numberOfPages',
        ], ['getPageCount', 'getPages', 'getNumberOfPages']);
        if ($pageCount !== null) {
            $extents[] = ['type' => '00', 'value' => (string) $pageCount, 'unit' => '03'];
        }

        // OMP core exposes front/back matter page counts directly.
        $front = $this->positiveIntegerFromFormat($format, ['frontMatter'], ['getFrontMatter']);
        if ($front !== null) {
            $extents[] = ['type' => '03', 'value' => (string) $front, 'unit' => '03'];
        }
        $back = $this->positiveIntegerFromFormat($format, ['backMatter'], ['getBackMatter']);
        if ($back !== null) {
            $extents[] = ['type' => '04', 'value' => (string) $back, 'unit' => '03'];
        }

        return $this->uniqueRows($extents);
    }

    /** @param array<int,object> $formats @return array<int,array{relationCode:string,isbn13:string}> */
    private function relatedProducts(array $formats, string $currentIsbn): array
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

    /** @return array{0:?object,1:array<int,object>} */
    private function formatForBook(object $publication, object $context, string $isbn13): array
    {
        $formats = $this->publicationFormats($publication, $context);
        $target = IdentifierNormalizer::preferredIsbn13($isbn13) ?? $isbn13;
        foreach ($formats as $format) {
            if (in_array($target, $this->formatIsbns($format), true)) {
                return [$format, $formats];
            }
        }
        return [null, $formats];
    }

    /** @return array<int,object> */
    private function publicationFormats(object $publication, object $context): array
    {
        try {
            $dao = DAORegistry::getDAO('PublicationFormatDAO');
            if ($dao && method_exists($dao, 'getByPublicationId')) {
                $formats = $dao->getByPublicationId((int) $publication->getId(), (int) $context->getId());
                if (is_array($formats)) {
                    return array_values($formats);
                }
            }
        } catch (Throwable) {
            // Embedded-data fallback below keeps custom/test objects compatible.
        }
        $embedded = method_exists($publication, 'getData') ? ($publication->getData('publicationFormats') ?? []) : [];
        return is_array($embedded) ? array_values($embedded) : [];
    }

    /** @return string[] */
    private function formatIsbns(object $format): array
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

    /** @param string[] $fields @param string[] $getters */
    private function positiveIntegerFromFormat(object $format, array $fields, array $getters): ?int
    {
        foreach ($getters as $getter) {
            if (!method_exists($format, $getter)) {
                continue;
            }
            try {
                $value = $format->{$getter}();
                if (is_numeric($value) && (int) $value > 0) {
                    return (int) $value;
                }
            } catch (Throwable) {
                // Try data fields below.
            }
        }
        if (method_exists($format, 'getData')) {
            foreach ($fields as $field) {
                try {
                    $value = $format->getData($field);
                } catch (Throwable) {
                    continue;
                }
                if (is_numeric($value) && (int) $value > 0) {
                    return (int) $value;
                }
            }
        }
        return null;
    }

    /** @return string[] */
    private function metadataValues(object $object, string $field, string $locale): array
    {
        $candidates = [];
        if (method_exists($object, 'getData')) {
            try {
                $candidates[] = $object->getData($field, $locale);
            } catch (Throwable) {
                try {
                    $candidates[] = $object->getData($field);
                } catch (Throwable) {
                }
            }
            try {
                $candidates[] = $object->getData($field);
            } catch (Throwable) {
            }
        }
        if (method_exists($object, 'getLocalizedData')) {
            try {
                $candidates[] = $object->getLocalizedData($field);
            } catch (Throwable) {
            }
        }

        $values = [];
        foreach ($candidates as $candidate) {
            $this->flattenMetadataValue($candidate, $values);
        }
        $clean = [];
        foreach ($values as $value) {
            $value = $this->cleanText((string) $value);
            if ($value !== '' && !in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }
        return $clean;
    }

    /** @param string[] $out */
    private function flattenMetadataValue(mixed $value, array &$out): void
    {
        if (is_string($value) || is_numeric($value)) {
            $out[] = (string) $value;
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (isset($value['name']) && (is_string($value['name']) || is_numeric($value['name']))) {
            $out[] = (string) $value['name'];
            return;
        }
        foreach ($value as $item) {
            $this->flattenMetadataValue($item, $out);
        }
    }

    private function firstLocalizedText(object $object, string $field, string $locale): ?string
    {
        foreach ($this->metadataValues($object, $field, $locale) as $value) {
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/<\s*\/?\s*(?:p|div|br|li|h[1-6])\b[^>]*>/i', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function uniqueRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $unique[$key] = $row;
        }
        return array_values($unique);
    }
}
