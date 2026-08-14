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
 * Encrypt reversible transport credentials with the OMP application key.
 *
 * Incoming HTTP crawler passwords remain one-way password hashes. This class
 * is only for outbound transports (SFTP/FTP/GCS), where the plugin must be
 * able to recover the credential in order to open a connection.
 */
final class SecretStore
{
    private const PREFIX = 'gbsec:v1:';
    private const AAD = 'googleBooks:transport-secret:v1';

    public static function encrypt(string $plaintext, ?string $applicationKey = null): string
    {
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to protect Google Books transport credentials.');
        }

        $key = self::key($applicationKey);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            16,
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt Google Books transport credentials.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $stored, ?string $applicationKey = null): string
    {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new RuntimeException('Unsupported Google Books encrypted-secret format.');
        }
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required to read Google Books transport credentials.');
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('The stored Google Books transport credential is invalid.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::key($applicationKey),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt the stored Google Books transport credential.');
        }
        return $plaintext;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(?string $applicationKey): string
    {
        $applicationKey ??= trim((string) Config::getVar('general', 'app_key'));
        if ($applicationKey === '') {
            throw new RuntimeException('OMP general.app_key is required before reversible transport credentials can be stored.');
        }
        return hash_hmac('sha256', self::AAD, $applicationKey, true);
    }
}
