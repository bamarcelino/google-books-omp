<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use InvalidArgumentException;

final class SftpEndpoint
{
    /**
     * Normalize a manager-supplied SFTP endpoint without ever accepting URL
     * userinfo. The host field may be a bare host, host:port, a complete
     * sftp:// URL, or a bracketed IPv6 endpoint. An URL path is used as the
     * remote root only when the dedicated remote-root field is empty.
     *
     * @return array{host:string,port:int,remoteRoot:string,inputHadScheme:bool,inputHadExplicitPort:bool,inputHadPath:bool,normalized:bool}
     */
    public static function parse(string $rawHost, int $configuredPort = 22, string $configuredRoot = ''): array
    {
        $configuredPort = self::validatePort($configuredPort > 0 ? $configuredPort : 22);
        $configuredRoot = self::normalizeRoot($configuredRoot);
        $input = self::cleanInput($rawHost);

        if ($input === '') {
            return [
                'host' => '',
                'port' => $configuredPort,
                'remoteRoot' => $configuredRoot,
                'inputHadScheme' => false,
                'inputHadExplicitPort' => false,
                'inputHadPath' => false,
                'normalized' => false,
            ];
        }

        // Some copied documentation escapes the colon as sftp\://. Treat that
        // representation exactly like sftp:// rather than creating host=sftp.
        $input = preg_replace('#^sftp\\\\://#i', 'sftp://', $input) ?? $input;
        $inputHadScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) === 1;

        if (!$inputHadScheme && filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parseTarget = 'sftp://[' . $input . ']';
        } else {
            $parseTarget = $inputHadScheme ? $input : 'sftp://' . $input;
        }

        $parts = parse_url($parseTarget);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('The SFTP endpoint could not be parsed.');
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? 'sftp')));
        if ($scheme !== 'sftp') {
            throw new InvalidArgumentException('The SFTP endpoint must use the sftp:// scheme or a bare hostname.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Do not place SFTP usernames or passwords inside the server/host field.');
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || preg_match('/[\\s\\x00-\\x1F\\x7F\\/\\\\?#]/u', $host)) {
            throw new InvalidArgumentException('A valid SFTP hostname or IP address is required.');
        }

        $inputHadExplicitPort = isset($parts['port']);
        $port = self::validatePort($inputHadExplicitPort ? (int) $parts['port'] : $configuredPort);
        $pathRoot = isset($parts['path']) ? self::normalizeRoot(rawurldecode((string) $parts['path'])) : '';
        $remoteRoot = $configuredRoot !== '' ? $configuredRoot : $pathRoot;
        $inputHadPath = $pathRoot !== '';

        return [
            'host' => $host,
            'port' => $port,
            'remoteRoot' => $remoteRoot,
            'inputHadScheme' => $inputHadScheme,
            'inputHadExplicitPort' => $inputHadExplicitPort,
            'inputHadPath' => $inputHadPath,
            'normalized' => $inputHadScheme || $inputHadExplicitPort || $inputHadPath || $input !== $host,
        ];
    }

    private static function cleanInput(string $value): string
    {
        $value = trim($value);
        return preg_replace('/[\\x{200B}-\\x{200D}\\x{2060}\\x{FEFF}]/u', '', $value) ?? $value;
    }

    private static function validatePort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The SFTP port must be between 1 and 65535.');
        }
        return $port;
    }

    private static function normalizeRoot(string $root): string
    {
        $root = trim(str_replace('\\', '/', $root));
        $root = preg_replace('#/+#', '/', $root) ?? $root;
        return trim($root, '/');
    }
}
