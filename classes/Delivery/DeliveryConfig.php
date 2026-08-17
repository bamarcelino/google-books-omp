<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use APP\plugins\generic\googleBooks\classes\Security\SecretStore;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;

final class DeliveryConfig
{
    public const HTTP_PULL = 'http_pull';
    public const GOOGLE_SFTP = 'google_sftp';
    public const PUBLISHER_SFTP = 'publisher_sftp';
    public const PUBLISHER_FTP = 'publisher_ftp';
    public const GCS = 'gcs';
    public const LOCAL_EXPORT = 'local_export';

    /** @return string[] */
    public static function modes(): array
    {
        return [
            self::HTTP_PULL,
            self::GOOGLE_SFTP,
            self::PUBLISHER_SFTP,
            self::PUBLISHER_FTP,
            self::GCS,
            self::LOCAL_EXPORT,
        ];
    }

    public static function mode(GoogleBooksPlugin $plugin, int $contextId): string
    {
        $mode = trim((string) $plugin->getSetting($contextId, 'deliveryMode'));
        return in_array($mode, self::modes(), true) ? $mode : self::HTTP_PULL;
    }

    /** @return array<string,mixed> */
    public static function forContext(GoogleBooksPlugin $plugin, int $contextId): array
    {
        $mode = self::mode($plugin, $contextId);
        $config = [
            'mode' => $mode,
            'enabled' => $plugin->boolSetting($contextId, 'feedEnabled', false),
            'deliverOnixFull' => $plugin->boolSetting($contextId, 'deliverOnixFull', true),
            'deliverOnixRights' => $plugin->boolSetting($contextId, 'deliverOnixRights', true),
            'deliverEbooks' => $plugin->boolSetting($contextId, 'deliverEbooks', true),
            'deliverValidation' => $plugin->boolSetting($contextId, 'deliverValidation', true),
        ];

        if ($mode === self::HTTP_PULL) {
            $config += [
                'username' => trim((string) $plugin->getSetting($contextId, 'feedUsername')),
                'passwordHash' => (string) $plugin->getSetting($contextId, 'feedPasswordHash'),
            ];
            return $config;
        }

        if (in_array($mode, [self::GOOGLE_SFTP, self::PUBLISHER_SFTP], true)) {
            $prefix = $mode === self::GOOGLE_SFTP ? 'googleSftp' : 'publisherSftp';
            $endpoint = SftpEndpoint::parse(
                (string) $plugin->getSetting($contextId, $prefix . 'Host'),
                (int) ($plugin->getSetting($contextId, $prefix . 'Port') ?: 22),
                (string) $plugin->getSetting($contextId, $prefix . 'RemoteRoot'),
            );
            $config += [
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
                'username' => trim((string) $plugin->getSetting($contextId, $prefix . 'Username')),
                'password' => self::secret($plugin, $contextId, $prefix . 'PasswordEncrypted'),
                'privateKey' => self::secret($plugin, $contextId, $prefix . 'PrivateKeyEncrypted'),
                'privateKeyPassphrase' => self::secret($plugin, $contextId, $prefix . 'PrivateKeyPassphraseEncrypted'),
                'authMode' => in_array((string) $plugin->getSetting($contextId, $prefix . 'AuthMode'), ['password', 'private_key'], true)
                    ? (string) $plugin->getSetting($contextId, $prefix . 'AuthMode')
                    : 'password',
                'remoteRoot' => $endpoint['remoteRoot'],
                'hostKeyFingerprint' => trim((string) $plugin->getSetting($contextId, $prefix . 'HostKeyFingerprint')),
                'endpointNormalized' => $endpoint['normalized'],
            ];
            return $config;
        }

        if ($mode === self::PUBLISHER_FTP) {
            $config += [
                'host' => trim((string) $plugin->getSetting($contextId, 'publisherFtpHost')),
                'port' => max(1, (int) ($plugin->getSetting($contextId, 'publisherFtpPort') ?: 21)),
                'username' => trim((string) $plugin->getSetting($contextId, 'publisherFtpUsername')),
                'password' => self::secret($plugin, $contextId, 'publisherFtpPasswordEncrypted'),
                'remoteRoot' => self::remoteRoot((string) $plugin->getSetting($contextId, 'publisherFtpRemoteRoot')),
                'tls' => $plugin->boolSetting($contextId, 'publisherFtpTls', false),
                'passive' => $plugin->boolSetting($contextId, 'publisherFtpPassive', true),
            ];
            return $config;
        }

        if ($mode === self::GCS) {
            $config += [
                'bucket' => trim((string) $plugin->getSetting($contextId, 'gcsBucket')),
                'prefix' => trim((string) $plugin->getSetting($contextId, 'gcsPrefix'), '/'),
                'serviceAccountJson' => self::secret($plugin, $contextId, 'gcsServiceAccountEncrypted'),
                'googleReaderServiceAccount' => trim((string) $plugin->getSetting($contextId, 'gcsGoogleReaderServiceAccount')),
            ];
            return $config;
        }

        return $config;
    }

    public static function transportKey(array $config): string
    {
        $identity = $config['mode'] ?? self::HTTP_PULL;
        foreach (['host', 'port', 'remoteRoot', 'bucket', 'prefix', 'username'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $identity .= '|' . $key . '=' . (string) $config[$key];
            }
        }
        return substr((string) ($config['mode'] ?? self::HTTP_PULL), 0, 30) . ':' . substr(hash('sha256', $identity), 0, 24);
    }

    private static function secret(GoogleBooksPlugin $plugin, int $contextId, string $name): string
    {
        $stored = (string) $plugin->getSetting($contextId, $name);
        return $stored === '' ? '' : SecretStore::decrypt($stored);
    }

    private static function remoteRoot(string $root): string
    {
        $root = trim(str_replace('\\', '/', $root));
        $root = preg_replace('#/+#', '/', $root) ?? $root;
        return trim($root, '/');
    }
}
