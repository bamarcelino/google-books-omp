<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

final class TransportCapabilities
{
    /**
     * @return array<string,bool|array<int,string>>
     */
    public static function detect(): array
    {
        $curlAvailable = function_exists('curl_init') && function_exists('curl_version');
        $protocols = [];
        if ($curlAvailable) {
            $info = curl_version();
            $protocols = array_map('strtolower', is_array($info['protocols'] ?? null) ? $info['protocols'] : []);
        }
        return self::evaluate(
            $curlAvailable,
            $protocols,
            function_exists('ftp_connect'),
            function_exists('ftp_ssl_connect'),
            function_exists('openssl_sign'),
            class_exists('ZipArchive'),
        );
    }

    /**
     * Pure capability evaluator used by tests.
     *
     * @param string[] $curlProtocols
     * @return array<string,bool|array<int,string>>
     */
    public static function evaluate(
        bool $curlAvailable,
        array $curlProtocols,
        bool $ftpAvailable,
        bool $ftpsAvailable,
        bool $opensslAvailable,
        bool $zipAvailable,
    ): array {
        $protocols = array_values(array_unique(array_map('strtolower', $curlProtocols)));
        return [
            'curl' => $curlAvailable,
            'curlProtocols' => $protocols,
            'httpPull' => true,
            'googleSftpDropbox' => $curlAvailable && in_array('sftp', $protocols, true),
            'publisherSftp' => $curlAvailable && in_array('sftp', $protocols, true),
            'publisherFtp' => $ftpAvailable,
            'publisherFtps' => $ftpsAvailable,
            'gcs' => $curlAvailable && $opensslAvailable && in_array('https', $protocols, true),
            'localExport' => true,
            'zipArchive' => $zipAvailable,
        ];
    }
}
