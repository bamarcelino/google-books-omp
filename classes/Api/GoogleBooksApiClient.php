<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Api;

use APP\plugins\generic\googleBooks\classes\Discovery\GoogleBooksMatch;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

final class GoogleBooksApiClient
{
    private const BASE_URL = 'https://www.googleapis.com/books/v1/volumes';
    private const TRANSIENT_HTTP = [429, 500, 502, 503, 504];
    private const MAX_ATTEMPTS = 4;

    /** @var null|callable(string,array<string,mixed>):array<string,mixed> */
    private $transport;

    public function __construct(
        private ?string $apiKey = null,
        private ?string $partnerId = null,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function findByIsbn(string $rawIsbn, ?string $title = null): GoogleBooksMatch
    {
        $isbn13 = IdentifierNormalizer::preferredIsbn13($rawIsbn);
        if ($isbn13 === null) {
            return new GoogleBooksMatch(false);
        }
        $isbn10 = IdentifierNormalizer::isbn13To10($isbn13);
        $equivalents = array_values(array_unique(array_filter([$isbn13, $isbn10])));

        // Query the canonical ISBN-13 first. Google usually returns both ISBN-13
        // and ISBN-10 industryIdentifiers on the same Volume, so immediately
        // querying both identifiers doubles API traffic on large catalogues
        // without improving normal-case matching. ISBN-10 is a fallback only.
        $candidates = $this->searchOne('isbn:' . $isbn13, $equivalents, false);
        if ($candidates === [] && $isbn10 !== null) {
            $candidates = $this->searchOne('isbn:' . $isbn10, $equivalents, false);
        }

        // The partner parameter restricts results. It is therefore only a
        // fallback after a global lookup, so an existing public record cannot
        // be hidden merely because it is not returned under the configured
        // Partner ID.
        if ($candidates === [] && $this->partnerId) {
            $candidates = $this->searchOne('isbn:' . $isbn13, $equivalents, true);
            if ($candidates === [] && $isbn10 !== null) {
                $candidates = $this->searchOne('isbn:' . $isbn10, $equivalents, true);
            }
        }

        // Newly ingested Partner Center records can be visible on the Books or
        // Play storefront before the public volumes.list ISBN field index is
        // complete. Broader official list queries can still surface the Volume.
        // searchOne() always checks industryIdentifiers against the canonical
        // ISBN equivalents, so neither a plain-text nor a title result can be
        // linked on title similarity alone.
        if ($candidates === []) {
            $candidates = $this->searchOne($isbn13, $equivalents, false);
        }
        if ($candidates === [] && $this->partnerId) {
            $candidates = $this->searchOne($isbn13, $equivalents, true);
        }

        $titleQuery = $this->titleSearchQuery($title);
        if ($candidates === [] && $titleQuery !== null) {
            $candidates = $this->searchOne($titleQuery, $equivalents, false);
        }
        if ($candidates === [] && $titleQuery !== null && $this->partnerId) {
            $candidates = $this->searchOne($titleQuery, $equivalents, true);
        }

        if ($candidates === []) {
            return new GoogleBooksMatch(false);
        }
        if (count($candidates) > 1) {
            return new GoogleBooksMatch(false, ambiguous: true, candidateCount: count($candidates));
        }

        $candidate = reset($candidates);
        return new GoogleBooksMatch(
            true,
            $candidate['volumeId'],
            $candidate['selfLink'],
            $candidate['infoLink'],
            $candidate['previewLink'],
            $candidate['matched'],
            $candidate['title'],
            $candidate['publisher'],
            false,
            1,
            $candidate['buyLink'],
            $candidate['saleability'],
            $candidate['isEbook'],
        );
    }

    /**
     * @param string[] $equivalents
     * @return array<string,array{volumeId:string,selfLink:?string,infoLink:?string,previewLink:?string,matched:array<int,string>,title:?string,publisher:?string,buyLink:?string,saleability:?string,isEbook:?bool}>
     */
    private function searchOne(string $query, array $equivalents, bool $withPartner): array
    {
        $data = $this->request([
            'q' => $query,
            'maxResults' => 40,
            'projection' => 'full',
            'printType' => 'books',
        ], $withPartner);

        $candidates = [];
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $identifiers = [];
            foreach (($item['volumeInfo']['industryIdentifiers'] ?? []) as $identifier) {
                $value = (string) ($identifier['identifier'] ?? '');
                foreach (IdentifierNormalizer::isbnEquivalents($value) as $normalized) {
                    $identifiers[] = $normalized;
                }
            }
            $identifiers = array_values(array_unique($identifiers));
            $matched = array_values(array_intersect($equivalents, $identifiers));
            if ($matched === []) {
                continue;
            }

            $volumeId = isset($item['id']) ? trim((string) $item['id']) : '';
            if ($volumeId === '') {
                continue;
            }
            $saleInfo = is_array($item['saleInfo'] ?? null) ? $item['saleInfo'] : [];
            $saleability = isset($saleInfo['saleability'])
                ? strtoupper(trim((string) $saleInfo['saleability']))
                : null;
            if ($saleability === '') {
                $saleability = null;
            }
            $isEbook = array_key_exists('isEbook', $saleInfo)
                ? (bool) $saleInfo['isEbook']
                : null;
            $candidates[$volumeId] = [
                'volumeId' => $volumeId,
                'selfLink' => $this->safeUrl(isset($item['selfLink']) ? (string) $item['selfLink'] : null),
                'infoLink' => $this->safeUrl(isset($item['volumeInfo']['infoLink']) ? (string) $item['volumeInfo']['infoLink'] : null),
                'previewLink' => $this->safeUrl(isset($item['volumeInfo']['previewLink']) ? (string) $item['volumeInfo']['previewLink'] : null),
                'matched' => $matched,
                'title' => isset($item['volumeInfo']['title']) ? (string) $item['volumeInfo']['title'] : null,
                'publisher' => isset($item['volumeInfo']['publisher']) ? (string) $item['volumeInfo']['publisher'] : null,
                'buyLink' => $this->safeUrl(isset($saleInfo['buyLink']) ? (string) $saleInfo['buyLink'] : null),
                'saleability' => $saleability,
                'isEbook' => $isEbook,
            ];
        }
        return $candidates;
    }

    private function titleSearchQuery(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/[\\x00-\\x1F\\x7F"\\\\]+/u', ' ', $title) ?? '';
        $title = trim(preg_replace('/\\s+/u', ' ', $title) ?? '');
        if ($title === '') {
            return null;
        }

        $title = function_exists('mb_substr')
            ? mb_substr($title, 0, 180, 'UTF-8')
            : substr($title, 0, 180);

        return 'intitle:"' . trim($title) . '"';
    }

    private function safeUrl(?string $url): ?string
    {
        if ($url === null || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** @return array<string,mixed> */
    private function request(array $query, bool $withPartner = false): array
    {
        if ($this->apiKey) {
            $query['key'] = $this->apiKey;
        }
        if ($withPartner && $this->partnerId) {
            $query['partner'] = $this->partnerId;
        }

        if ($this->transport !== null) {
            try {
                $result = ($this->transport)(self::BASE_URL, $query);
            } catch (Throwable $e) {
                throw new RuntimeException('Google Books API request failed: transport error', 0, $e);
            }
            if (!is_array($result)) {
                throw new RuntimeException('Google Books transport must return an array.');
            }
            return $result;
        }

        $client = new Client([
            'timeout' => 12,
            'connect_timeout' => 5,
            'http_errors' => true,
            'headers' => ['User-Agent' => 'GoogleBooksIntegrationForOMP/0.1.2.12'],
        ]);

        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $client->get(self::BASE_URL, ['query' => $query]);
                $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Invalid response from Google Books API.');
                }
                return $decoded;
            } catch (Throwable $e) {
                $last = $e;
                $status = $e instanceof RequestException && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : null;
                if ($attempt >= self::MAX_ATTEMPTS || ($status !== null && !in_array($status, self::TRANSIENT_HTTP, true))) {
                    break;
                }

                $delayMs = 1000 * (2 ** ($attempt - 1));
                if ($e instanceof RequestException && $e->getResponse()) {
                    $retryAfter = trim($e->getResponse()->getHeaderLine('Retry-After'));
                    if ($retryAfter !== '' && ctype_digit($retryAfter)) {
                        $delayMs = min(15000, max($delayMs, (int) $retryAfter * 1000));
                    }
                }
                usleep($delayMs * 1000);
            }
        }

        $detail = 'request error';
        if ($last instanceof RequestException && $last->getResponse()) {
            $detail = 'HTTP ' . $last->getResponse()->getStatusCode();
        } elseif ($last instanceof RuntimeException) {
            $detail = $last->getMessage();
        }
        throw new RuntimeException('Google Books API request failed: ' . $detail, 0, $last);
    }
}
