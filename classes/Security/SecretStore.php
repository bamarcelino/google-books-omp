<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Security;

use PKP\config\Config;
use RuntimeException;

/**
 * Encrypt recoverable Google Books credentials with the OMP application key.
 *
 * Incoming HTTP crawler passwords remain one-way password hashes. This class
 * is only for values the plugin must recover later in order to authenticate
 * an outbound request, such as SFTP/FTP/GCS credentials and the Google Books
 * API key used by discovery jobs.
 */
final class SecretStore
{
    private const PREFIX = 'gbsec:v1:';
    private const AAD = 'googleBooks:transport-secret:v1';
    private const API_PREFIX = 'gbapi:v1:';
    private const API_AAD = 'googleBooks:api-key:v1';

    public static function encrypt(string $plaintext, ?string $applicationKey = null): string
    {
        return self::encryptEnvelope($plaintext, self::PREFIX, self::AAD, $applicationKey);
    }

    public static function decrypt(string $stored, ?string $applicationKey = null): string
    {
        return self::decryptEnvelope($stored, self::PREFIX, self::AAD, $applicationKey);
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function encryptApiKey(string $plaintext, ?string $applicationKey = null): string
    {
        return self::encryptEnvelope($plaintext, self::API_PREFIX, self::API_AAD, $applicationKey);
    }

    public static function decryptApiKey(string $stored, ?string $applicationKey = null): string
    {
        return self::decryptEnvelope($stored, self::API_PREFIX, self::API_AAD, $applicationKey);
    }

    public static function isApiKeyEncrypted(string $value): bool
    {
        return str_starts_with($value, self::API_PREFIX);
    }

    private static function encryptEnvelope(
        string $plaintext,
        string $prefix,
        string $aad,
        ?string $applicationKey,
    ): string {
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to protect Google Books credentials.');
        }

        $key = self::key($applicationKey, $aad);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16,
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt Google Books credentials.');
        }

        return $prefix . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decryptEnvelope(
        string $stored,
        string $prefix,
        string $aad,
        ?string $applicationKey,
    ): string {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, $prefix)) {
            throw new RuntimeException('Unsupported Google Books encrypted-secret format.');
        }
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required to read Google Books credentials.');
        }

        $raw = base64_decode(substr($stored, strlen($prefix)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('The stored Google Books credential is invalid.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::key($applicationKey, $aad),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt the stored Google Books credential.');
        }
        return $plaintext;
    }

    private static function key(?string $applicationKey, string $aad): string
    {
        $applicationKey ??= trim((string) Config::getVar('general', 'app_key'));
        if ($applicationKey === '') {
            throw new RuntimeException('OMP general.app_key is required before reversible Google Books credentials can be stored.');
        }
        return hash_hmac('sha256', $aad, $applicationKey, true);
    }
}
