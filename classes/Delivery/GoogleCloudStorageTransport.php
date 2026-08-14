<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use RuntimeException;

final class GoogleCloudStorageTransport
{
    /** @var array<string,mixed>|null */
    private ?array $serviceAccount = null;
    private ?string $accessToken = null;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function test(): array
    {
        $bucket = $this->bucket();
        $response = $this->request(
            'GET',
            'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($bucket),
            null,
            ['Accept: application/json'],
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Google Cloud Storage bucket access test failed with HTTP ' . $response['status'] . '.');
        }
        return ['ok' => true, 'message' => 'Google Cloud Storage authentication and bucket access succeeded.'];
    }

    /** @param resource $stream */
    public function upload(string $relativePath, $stream, int $size, string $mime): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Google Cloud Storage upload requires a readable stream.');
        }
        $object = $this->objectName($relativePath);
        $url = 'https://storage.googleapis.com/' . rawurlencode($this->bucket()) . '/' . $this->encodeObjectPath($object);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Google Cloud Storage upload.');
        }
        try {
            rewind($stream);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token(),
                    'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
                    'Content-Length: ' . $size,
                ],
            ]);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new RuntimeException('Google Cloud Storage upload failed: ' . curl_error($ch));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Google Cloud Storage upload failed for ' . $relativePath . ' with HTTP ' . $status . '.');
            }
        } finally {
            curl_close($ch);
        }
    }

    public function delete(string $relativePath): void
    {
        $object = $this->objectName($relativePath);
        $response = $this->request(
            'DELETE',
            'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket()) . '/o/' . rawurlencode($object),
            null,
            ['Accept: application/json'],
        );
        if (!in_array($response['status'], [200, 204, 404], true)) {
            throw new RuntimeException('Unable to delete stale Google Cloud Storage object; HTTP ' . $response['status'] . '.');
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        $account = $this->serviceAccount();
        $clientEmail = trim((string) ($account['client_email'] ?? ''));
        $privateKey = (string) ($account['private_key'] ?? '');
        $tokenUri = trim((string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('The Google Cloud Storage service account JSON must contain client_email and private_key.');
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3300,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the Google Cloud Storage service-account assertion.');
        }
        $assertion = $unsigned . '.' . $this->base64Url($signature);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->rawRequest(
            'POST',
            $tokenUri,
            $body,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            false,
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Google Cloud Storage OAuth token request failed with HTTP ' . $response['status'] . '.');
        }
        $decoded = json_decode($response['body'], true);
        $token = is_array($decoded) ? trim((string) ($decoded['access_token'] ?? '')) : '';
        if ($token === '') {
            throw new RuntimeException('Google Cloud Storage OAuth response did not include an access token.');
        }
        return $this->accessToken = $token;
    }

    /** @return array<string,mixed> */
    private function serviceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }
        $raw = trim((string) ($this->config['serviceAccountJson'] ?? ''));
        if ($raw === '') {
            throw new RuntimeException('A publisher Google Cloud service account JSON is required for direct bucket uploads.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The Google Cloud service account JSON is invalid.');
        }
        return $this->serviceAccount = $decoded;
    }

    private function bucket(): string
    {
        $bucket = trim((string) ($this->config['bucket'] ?? ''));
        if ($bucket === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{1,221}[a-z0-9]$/', $bucket)) {
            throw new RuntimeException('A valid Google Cloud Storage bucket name is required.');
        }
        if (!function_exists('curl_init') || !function_exists('openssl_sign')) {
            throw new RuntimeException('Google Cloud Storage delivery requires PHP cURL and OpenSSL.');
        }
        return $bucket;
    }

    private function objectName(string $relativePath): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        return $prefix === '' ? $relativePath : $prefix . '/' . $relativePath;
    }

    private function encodeObjectPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $headers[] = 'Authorization: Bearer ' . $this->token();
        return $this->rawRequest($method, $url, $body, $headers, true);
    }

    /** @return array{status:int,body:string} */
    private function rawRequest(string $method, string $url, ?string $body, array $headers, bool $authenticated): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Google Cloud Storage delivery.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the Google Cloud Storage HTTP client.');
        }
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $options);
            $responseBody = curl_exec($ch);
            if ($responseBody === false) {
                throw new RuntimeException('Google Cloud Storage request failed: ' . curl_error($ch));
            }
            return [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => (string) $responseBody,
            ];
        } finally {
            curl_close($ch);
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
