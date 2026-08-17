<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use RuntimeException;

final class SftpTransport
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $endpoint = SftpEndpoint::parse(
            (string) ($this->config['host'] ?? ''),
            (int) ($this->config['port'] ?? 22),
            (string) ($this->config['remoteRoot'] ?? ''),
        );
        $this->config['host'] = $endpoint['host'];
        $this->config['port'] = $endpoint['port'];
        $this->config['remoteRoot'] = $endpoint['remoteRoot'];
        $this->config['endpointNormalized'] = $endpoint['normalized'];
    }

    /** @return array<string,mixed> */
    public function test(): array
    {
        $this->assertAvailable();
        $resolvedIps = $this->resolveIps();
        $attempt = $this->connectAttempt(null);
        $ipStrategy = 'automatic';

        if (!$attempt['ok'] && $this->shouldRetryIpv4((int) $attempt['curlCode'])) {
            $ipv4 = $this->connectAttempt(defined('CURL_IPRESOLVE_V4') ? (int) CURL_IPRESOLVE_V4 : null);
            if ($ipv4['ok']) {
                $attempt = $ipv4;
                $ipStrategy = 'ipv4_fallback';
            } elseif ((string) ($ipv4['error'] ?? '') !== '') {
                // Prefer the IPv4 result when both attempts fail because it is
                // the most actionable result for common outbound-firewall cases.
                $attempt = $ipv4;
                $ipStrategy = 'ipv4_retry_failed';
            }
        }

        $base = [
            'host' => (string) $this->config['host'],
            'port' => (int) $this->config['port'],
            'remoteRoot' => (string) ($this->config['remoteRoot'] ?? ''),
            'authMode' => (string) ($this->config['authMode'] ?? 'password'),
            'resolvedIps' => $resolvedIps,
            'primaryIp' => (string) ($attempt['primaryIp'] ?? ''),
            'curlCode' => (int) ($attempt['curlCode'] ?? 0),
            'osErrno' => (int) ($attempt['osErrno'] ?? 0),
            'ipStrategy' => $ipStrategy,
            'endpointNormalized' => (bool) ($this->config['endpointNormalized'] ?? false),
        ];

        if (!$attempt['ok']) {
            $classification = self::classifyFailure(
                (int) $attempt['curlCode'],
                (int) $attempt['osErrno'],
                (string) $attempt['error'],
            );
            return $base + [
                'ok' => false,
                'stage' => $classification,
                'message' => $this->failureMessage($classification, $attempt, $resolvedIps, $ipStrategy),
            ];
        }

        $dropboxNote = ($this->config['mode'] ?? '') === DeliveryConfig::GOOGLE_SFTP
            ? ' This non-destructive Google Dropbox test does not require directory listing or create a probe file; actual write permission is confirmed by the first delivery.'
            : ' This non-destructive test validates connection setup without writing a remote probe file.';
        $message = sprintf(
            'SFTP/SSH connection setup succeeded for %s:%d%s. Primary IP: %s. IP strategy: %s. Authentication mode: %s.%s',
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) ($this->config['remoteRoot'] ?? '') !== '' ? ' root=' . (string) $this->config['remoteRoot'] : '',
            (string) ($attempt['primaryIp'] ?: 'not reported'),
            $ipStrategy,
            (string) ($this->config['authMode'] ?? 'password'),
            $dropboxNote,
        );

        return $base + ['ok' => true, 'stage' => 'connection_setup', 'message' => $message];
    }

    /** @param resource $stream */
    public function upload(string $relativePath, $stream, int $size, string $mime): void
    {
        $this->assertAvailable();
        if (!is_resource($stream)) {
            throw new RuntimeException('SFTP upload requires a readable stream.');
        }

        $attempt = $this->uploadAttempt($relativePath, $stream, $size, null);
        if (!$attempt['ok'] && $this->shouldRetryIpv4((int) $attempt['curlCode']) && $this->rewindForRetry($stream)) {
            $attempt = $this->uploadAttempt(
                $relativePath,
                $stream,
                $size,
                defined('CURL_IPRESOLVE_V4') ? (int) CURL_IPRESOLVE_V4 : null,
            );
        }
        if (!$attempt['ok']) {
            $classification = self::classifyFailure((int) $attempt['curlCode'], (int) $attempt['osErrno'], (string) $attempt['error']);
            throw new RuntimeException(
                'SFTP upload failed for ' . $relativePath . ': ' .
                $this->failureMessage($classification, $attempt, $this->resolveIps(), 'upload'),
            );
        }
    }

    public function delete(string $relativePath): void
    {
        $this->assertAvailable();
        $remotePath = '/' . ltrim($this->remotePath($relativePath), '/');
        $ch = curl_init($this->url(''));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the SFTP delete request.');
        }
        $tempKey = null;
        try {
            $options = $this->commonOptions($tempKey);
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_NOBODY] = true;
            $options[CURLOPT_QUOTE] = ['-rm ' . $remotePath];
            $options[CURLOPT_TIMEOUT] = 60;
            curl_setopt_array($ch, $options);
            curl_exec($ch); // cleanup is best-effort; ignored by caller when unsupported
        } finally {
            curl_close($ch);
            $this->removeTempKey($tempKey);
        }
    }

    /**
     * Classify a libcurl SFTP failure without exposing credentials.
     */
    public static function classifyFailure(int $curlCode, int $osErrno, string $error): string
    {
        $lower = strtolower($error);
        if ($curlCode === 6 || str_contains($lower, 'resolve host')) {
            return 'dns';
        }
        if ($osErrno === 111 || $osErrno === 61 || $osErrno === 10061 || str_contains($lower, 'connection refused')) {
            return 'tcp_refused';
        }
        if ($curlCode === 28 || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'timeout';
        }
        if ($curlCode === 67 || str_contains($lower, 'authentication') || str_contains($lower, 'login denied') || str_contains($lower, 'auth fail')) {
            return 'authentication';
        }
        if (str_contains($lower, 'host key') || str_contains($lower, 'fingerprint') || str_contains($lower, 'knownhosts')) {
            return 'host_key';
        }
        if ($curlCode === 9 || str_contains($lower, 'permission denied') || str_contains($lower, 'access denied')) {
            return 'remote_access';
        }
        if ($curlCode === 1 || $curlCode === 4 || str_contains($lower, 'unsupported protocol')) {
            return 'unsupported';
        }
        if ($curlCode === 7) {
            return 'tcp_connect';
        }
        return 'sftp';
    }

    private function assertAvailable(): void
    {
        $caps = TransportCapabilities::detect();
        if (!($caps['publisherSftp'] ?? false)) {
            throw new RuntimeException('This PHP/libcurl build does not provide the SFTP protocol.');
        }
        if (($this->config['host'] ?? '') === '' || ($this->config['username'] ?? '') === '') {
            throw new RuntimeException('SFTP host and username are required.');
        }
        $authMode = (string) ($this->config['authMode'] ?? 'password');
        if ($authMode === 'password' && ($this->config['password'] ?? '') === '') {
            throw new RuntimeException('An SFTP password is required for password authentication.');
        }
        if ($authMode === 'private_key' && ($this->config['privateKey'] ?? '') === '') {
            throw new RuntimeException('An SFTP private key is required for key authentication.');
        }
    }

    /** @return array{ok:bool,curlCode:int,osErrno:int,error:string,primaryIp:string,primaryPort:int,nameLookupMs:int,connectMs:int} */
    private function connectAttempt(?int $ipResolve): array
    {
        $ch = curl_init($this->url(''));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the SFTP client.');
        }
        $tempKey = null;
        try {
            $options = $this->commonOptions($tempKey);
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_TIMEOUT] = 30;
            $options[CURLOPT_CONNECTTIMEOUT] = 20;
            if (defined('CURLOPT_CONNECT_ONLY')) {
                $options[CURLOPT_CONNECT_ONLY] = true;
            } else {
                $options[CURLOPT_NOBODY] = true;
            }
            if (defined('CURLOPT_FRESH_CONNECT')) {
                $options[CURLOPT_FRESH_CONNECT] = true;
            }
            if (defined('CURLOPT_FORBID_REUSE')) {
                $options[CURLOPT_FORBID_REUSE] = true;
            }
            if ($ipResolve !== null && defined('CURLOPT_IPRESOLVE')) {
                $options[CURLOPT_IPRESOLVE] = $ipResolve;
            }
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            return $this->curlResult($ch, $result !== false);
        } finally {
            curl_close($ch);
            $this->removeTempKey($tempKey);
        }
    }

    /** @param resource $stream @return array{ok:bool,curlCode:int,osErrno:int,error:string,primaryIp:string,primaryPort:int,nameLookupMs:int,connectMs:int} */
    private function uploadAttempt(string $relativePath, $stream, int $size, ?int $ipResolve): array
    {
        $remotePath = $this->remotePath($relativePath);
        $ch = curl_init($this->url($relativePath));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the SFTP upload.');
        }
        $tempKey = null;
        try {
            $options = $this->commonOptions($tempKey);
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $stream;
            $options[CURLOPT_INFILESIZE] = $size;
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_TIMEOUT] = 600;
            if ($ipResolve !== null && defined('CURLOPT_IPRESOLVE')) {
                $options[CURLOPT_IPRESOLVE] = $ipResolve;
            }
            $directories = $this->directoryQuoteCommands($remotePath);
            if ($directories !== []) {
                $options[CURLOPT_QUOTE] = $directories;
            }
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            return $this->curlResult($ch, $result !== false);
        } finally {
            curl_close($ch);
            $this->removeTempKey($tempKey);
        }
    }

    /** @param resource $ch @return array{ok:bool,curlCode:int,osErrno:int,error:string,primaryIp:string,primaryPort:int,nameLookupMs:int,connectMs:int} */
    private function curlResult($ch, bool $ok): array
    {
        $osErrno = defined('CURLINFO_OS_ERRNO') ? (int) curl_getinfo($ch, CURLINFO_OS_ERRNO) : 0;
        $primaryIp = defined('CURLINFO_PRIMARY_IP') ? (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP) : '';
        $primaryPort = defined('CURLINFO_PRIMARY_PORT') ? (int) curl_getinfo($ch, CURLINFO_PRIMARY_PORT) : 0;
        $nameLookup = defined('CURLINFO_NAMELOOKUP_TIME') ? (float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) : 0.0;
        $connectTime = defined('CURLINFO_CONNECT_TIME') ? (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) : 0.0;
        return [
            'ok' => $ok,
            'curlCode' => (int) curl_errno($ch),
            'osErrno' => $osErrno,
            'error' => (string) curl_error($ch),
            'primaryIp' => $primaryIp,
            'primaryPort' => $primaryPort,
            'nameLookupMs' => (int) round($nameLookup * 1000),
            'connectMs' => (int) round($connectTime * 1000),
        ];
    }

    /** @param ?string $tempKey @return array<int,mixed> */
    private function commonOptions(?string &$tempKey): array
    {
        $options = [
            CURLOPT_USERPWD => (string) $this->config['username'] . ':' . (string) ($this->config['password'] ?? ''),
            CURLOPT_CONNECTTIMEOUT => 20,
        ];
        if (defined('CURLOPT_NOSIGNAL')) {
            $options[CURLOPT_NOSIGNAL] = true;
        }
        $authMode = (string) ($this->config['authMode'] ?? 'password');
        if (defined('CURLOPT_SSH_AUTH_TYPES')) {
            $options[CURLOPT_SSH_AUTH_TYPES] = $authMode === 'private_key'
                ? (defined('CURLSSH_AUTH_PUBLICKEY') ? CURLSSH_AUTH_PUBLICKEY : 2)
                : (defined('CURLSSH_AUTH_PASSWORD') ? CURLSSH_AUTH_PASSWORD : 1);
        }
        if ($authMode === 'private_key') {
            $tempKey = tempnam(sys_get_temp_dir(), 'gb-sftp-key-');
            if ($tempKey === false || file_put_contents($tempKey, (string) $this->config['privateKey']) === false) {
                throw new RuntimeException('Unable to prepare the temporary SFTP private key.');
            }
            @chmod($tempKey, 0600);
            if (defined('CURLOPT_SSH_PRIVATE_KEYFILE')) {
                $options[CURLOPT_SSH_PRIVATE_KEYFILE] = $tempKey;
            }
            if (defined('CURLOPT_SSH_PUBLIC_KEYFILE')) {
                // Let the SSH backend derive the public key where supported.
                $options[CURLOPT_SSH_PUBLIC_KEYFILE] = '';
            }
            if (($this->config['privateKeyPassphrase'] ?? '') !== '' && defined('CURLOPT_KEYPASSWD')) {
                $options[CURLOPT_KEYPASSWD] = (string) $this->config['privateKeyPassphrase'];
            }
        }
        $fingerprint = trim((string) ($this->config['hostKeyFingerprint'] ?? ''));
        if ($fingerprint !== '') {
            if (defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
                $options[CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256] = preg_replace('/^SHA256:/i', '', $fingerprint) ?? $fingerprint;
            } elseif (defined('CURLOPT_SSH_HOST_PUBLIC_KEY_MD5')) {
                $options[CURLOPT_SSH_HOST_PUBLIC_KEY_MD5] = preg_replace('/^MD5:/i', '', $fingerprint) ?? $fingerprint;
            }
        }
        return $options;
    }

    /** @return string[] */
    private function resolveIps(): array
    {
        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }
        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ipv6 = trim((string) ($record['ipv6'] ?? ''));
                    if ($ipv6 !== '') {
                        $ips[] = $ipv6;
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** @param array<string,mixed> $attempt @param string[] $resolvedIps */
    private function failureMessage(string $classification, array $attempt, array $resolvedIps, string $ipStrategy): string
    {
        $summary = match ($classification) {
            'dns' => 'DNS resolution failed before SFTP authentication.',
            'tcp_refused' => 'The TCP connection was refused before SSH/SFTP authentication. Check outbound firewall/egress rules and remote service availability.',
            'tcp_connect' => 'The TCP connection could not be established before SSH/SFTP authentication. Check outbound firewall, routing and the remote endpoint.',
            'timeout' => 'The TCP/SSH connection timed out before authentication. Check outbound firewall, provider egress rules and routing.',
            'authentication' => 'The remote SFTP service was reached but authentication was denied. Verify the username, password/private key and remote account activation.',
            'host_key' => 'SSH host-key verification failed. Verify the configured server fingerprint/known host data.',
            'remote_access' => 'The SFTP service was reached but remote access was denied. Verify the account permissions and remote root.',
            'unsupported' => 'The PHP/libcurl runtime does not support the requested SFTP operation.',
            default => 'The SFTP operation failed during connection setup.',
        };
        $detail = trim((string) ($attempt['error'] ?? ''));
        $resolved = $resolvedIps === [] ? 'none reported' : implode(', ', $resolvedIps);
        return sprintf(
            '%s Endpoint %s:%d; cURL=%d; OS errno=%d; primary IP=%s; resolved IPs=%s; IP strategy=%s%s',
            $summary,
            (string) $this->config['host'],
            (int) $this->config['port'],
            (int) ($attempt['curlCode'] ?? 0),
            (int) ($attempt['osErrno'] ?? 0),
            (string) (($attempt['primaryIp'] ?? '') ?: 'none'),
            $resolved,
            $ipStrategy,
            $detail !== '' ? '; libcurl=' . $detail : '.',
        );
    }

    private function shouldRetryIpv4(int $curlCode): bool
    {
        return in_array($curlCode, [6, 7, 28], true)
            && defined('CURLOPT_IPRESOLVE')
            && defined('CURL_IPRESOLVE_V4');
    }

    /** @param resource $stream */
    private function rewindForRetry($stream): bool
    {
        $meta = stream_get_meta_data($stream);
        return (bool) ($meta['seekable'] ?? false) && rewind($stream);
    }

    private function url(string $relativePath): string
    {
        $host = trim((string) $this->config['host']);
        $hostForUrl = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
        $port = (int) ($this->config['port'] ?? 22);
        $path = $this->remotePath($relativePath);
        $encoded = implode('/', array_map('rawurlencode', array_values(array_filter(explode('/', $path), static fn (string $v): bool => $v !== ''))));
        return 'sftp://' . $hostForUrl . ':' . $port . '/' . $encoded . ($relativePath === '' ? '/' : '');
    }

    private function remotePath(string $relativePath): string
    {
        $root = trim((string) ($this->config['remoteRoot'] ?? ''), '/');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        return $root === '' ? $relativePath : ($relativePath === '' ? $root : $root . '/' . $relativePath);
    }

    /** @return string[] */
    private function directoryQuoteCommands(string $remotePath): array
    {
        $parts = explode('/', trim(dirname($remotePath), '/.'));
        $commands = [];
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            $commands[] = '-mkdir ' . $current;
        }
        return $commands;
    }

    private function removeTempKey(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
