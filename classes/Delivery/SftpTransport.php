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
    }

    /** @return array{ok:bool,message:string} */
    public function test(): array
    {
        $this->assertAvailable();
        $ch = curl_init($this->url(''));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the SFTP client.');
        }
        $tempKey = null;
        try {
            $options = $this->commonOptions($tempKey);
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_DIRLISTONLY] = true;
            $options[CURLOPT_TIMEOUT] = 30;
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new RuntimeException('SFTP connection failed: ' . curl_error($ch));
            }
            return ['ok' => true, 'message' => 'SFTP connection and authentication succeeded.'];
        } finally {
            curl_close($ch);
            $this->removeTempKey($tempKey);
        }
    }

    /** @param resource $stream */
    public function upload(string $relativePath, $stream, int $size, string $mime): void
    {
        $this->assertAvailable();
        if (!is_resource($stream)) {
            throw new RuntimeException('SFTP upload requires a readable stream.');
        }
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
            $directories = $this->directoryQuoteCommands($remotePath);
            if ($directories !== []) {
                $options[CURLOPT_QUOTE] = $directories;
            }
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new RuntimeException('SFTP upload failed for ' . $relativePath . ': ' . curl_error($ch));
            }
        } finally {
            curl_close($ch);
            $this->removeTempKey($tempKey);
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

    /** @param ?string $tempKey @return array<int,mixed> */
    private function commonOptions(?string &$tempKey): array
    {
        $options = [
            CURLOPT_USERPWD => (string) $this->config['username'] . ':' . (string) ($this->config['password'] ?? ''),
            CURLOPT_CONNECTTIMEOUT => 20,
        ];
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

    private function url(string $relativePath): string
    {
        $host = trim((string) $this->config['host']);
        $port = (int) ($this->config['port'] ?? 22);
        $path = $this->remotePath($relativePath);
        $encoded = implode('/', array_map('rawurlencode', array_values(array_filter(explode('/', $path), static fn (string $v): bool => $v !== ''))));
        return 'sftp://' . $host . ':' . $port . '/' . $encoded . ($relativePath === '' ? '/' : '');
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
