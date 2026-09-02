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
    private const PUBLIC_ISBN_URL = 'https://books.google.com/books';
    private const TRANSIENT_HTTP = [429, 500, 502, 503, 504];
    private const MAX_ATTEMPTS = 4;

    /** @var null|callable(string,array<string,mixed>):array<string,mixed> */
    private $transport;

    /** @var null|callable(string):array{url?:string,body?:string}|string */
    private $publicResolverTransport;

    public function __construct(
        private ?string $apiKey = null,
        private ?string $partnerId = null,
        ?callable $transport = null,
        ?callable $publicResolverTransport = null,
    ) {
        $this->transport = $transport;
        $this->publicResolverTransport = $publicResolverTransport;
    }

    public function findByIsbn(string $rawIsbn, ?string $title = null): GoogleBooksMatch
    {
        $isbn13 = IdentifierNormalizer::preferredIsbn13($rawIsbn);
        if ($isbn13 === null) {
            return new GoogleBooksMatch(false);
        }
        $isbn10 = IdentifierNormalizer::isbn13To10($isbn13);
        $equivalents = array_values(array_unique(array_filter([$isbn13, $isbn10])));

        // Query the canonical ISBN-13 through a global lookup first. A
        // transient API quota failure does not prevent the public ISBN resolver
        // below from recovering an exact Google Books record without issuing
        // several more list requests.
        $apiFailure = null;
        try {
            $candidates = $this->searchOne('isbn:' . $isbn13, $equivalents, false);
        } catch (RuntimeException $e) {
            $apiFailure = $e;
            $candidates = [];
        }

        // Google Books' public ISBN resolver can expose a newly ingested Volume
        // before volumes.list indexes q=isbn:. The resolver is accepted only
        // when its bibliographic table contains an exact normalized ISBN.
        if ($candidates === []) {
            $candidates = $this->searchPublicIsbnPage($isbn13, $equivalents);
        }

        if ($candidates === [] && $apiFailure !== null) {
            throw $apiFailure;
        }

        // Retain bounded compatibility fallbacks without the former sequence
        // of plain-ISBN and title list queries that could exhaust per-minute
        // API quota across a large catalogue.
        if ($candidates === [] && $isbn10 !== null) {
            $candidates = $this->searchOne('isbn:' . $isbn10, $equivalents, false);
        }
        if ($candidates === [] && $this->partnerId) {
            $candidates = $this->searchOne('isbn:' . $isbn13, $equivalents, true);
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

    /**
     * @param string[] $equivalents
     * @return array<string,array{volumeId:string,selfLink:?string,infoLink:?string,previewLink:?string,matched:array<int,string>,title:?string,publisher:?string,buyLink:?string,saleability:?string,isEbook:?bool}>
     */
    private function searchPublicIsbnPage(string $isbn13, array $equivalents): array
    {
        $url = self::PUBLIC_ISBN_URL . '?vid=ISBN' . rawurlencode($isbn13);
        try {
            if ($this->publicResolverTransport !== null) {
                $response = ($this->publicResolverTransport)($url);
                $html = is_array($response) ? (string) ($response['body'] ?? '') : (string) $response;
            } else {
                $client = new Client([
                    'timeout' => 12,
                    'connect_timeout' => 5,
                    'http_errors' => true,
                    'allow_redirects' => true,
                    'headers' => ['User-Agent' => 'GoogleBooksIntegrationForOMP/0.1.2.15'],
                ]);
                $html = (string) $client->get($url)->getBody();
            }
        } catch (Throwable) {
            return [];
        }
        if ($html === '') {
            return [];
        }

        $metadataIsbns = [];
        if (preg_match(
            '~<td[^>]*class=["\']metadata_label["\'][^>]*>.*?ISBN.*?</td>\\s*<td[^>]*class=["\']metadata_value["\'][^>]*>(.*?)</td>~is',
            $html,
            $metadataMatch,
        ) === 1) {
            $metadataText = html_entity_decode(strip_tags($metadataMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach (preg_split('/\\s*[,;|]\\s*/u', $metadataText) ?: [] as $identifier) {
                foreach (IdentifierNormalizer::isbnEquivalents($identifier) as $normalized) {
                    $metadataIsbns[] = $normalized;
                }
            }
        }
        $metadataIsbns = array_values(array_unique($metadataIsbns));
        $matched = array_values(array_intersect($equivalents, $metadataIsbns));
        if ($matched === []) {
            return [];
        }

        $volumeId = '';
        if (preg_match('/["\']volume_id["\']\\s*:\\s*["\']([A-Za-z0-9_-]{6,64})["\']/', $html, $volumeMatch) === 1) {
            $volumeId = $volumeMatch[1];
        } elseif (preg_match(
            '~play\\.google\\.com/store/books/details[^"\'<>]*?(?:\\?|&amp;|&)id=([A-Za-z0-9_-]{6,64})~i',
            $html,
            $volumeMatch,
        ) === 1) {
            $volumeId = $volumeMatch[1];
        }
        if ($volumeId === '') {
            return [];
        }

        $title = null;
        if (preg_match('~<h1[^>]*class=["\'][^"\']*booktitle[^"\']*["\'][^>]*>(.*?)</h1>~is', $html, $titleMatch) === 1) {
            $title = trim(html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
        }

        $buyLink = null;
        if (preg_match(
            '~href=["\'](https://play\\.google\\.com/store/books/details[^"\'<>]+)["\']~i',
            $html,
            $buyMatch,
        ) === 1) {
            $buyLink = $this->safeUrl(html_entity_decode($buyMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $booksUrl = 'https://books.google.com/books?id=' . rawurlencode($volumeId);
        $candidates = [[
            'volumeId' => $volumeId,
            'selfLink' => self::BASE_URL . '/' . rawurlencode($volumeId),
            'infoLink' => $booksUrl,
            'previewLink' => $booksUrl,
            'matched' => $matched,
            'title' => $title,
            'publisher' => null,
            'buyLink' => $buyLink,
            'saleability' => $buyLink !== null ? 'FOR_SALE' : null,
            'isEbook' => str_contains($html, '"is_ebook":true') ? true : ($buyLink !== null ? true : null),
        ]];

        return [$volumeId => $candidates[0]];
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
            'headers' => ['User-Agent' => 'GoogleBooksIntegrationForOMP/0.1.2.15'],
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
