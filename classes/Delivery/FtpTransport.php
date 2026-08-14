<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use RuntimeException;

final class FtpTransport
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function test(): array
    {
        $connection = $this->connect();
        try {
            $root = trim((string) ($this->config['remoteRoot'] ?? ''), '/');
            if ($root !== '' && @ftp_chdir($connection, '/' . $root) === false && @ftp_chdir($connection, $root) === false) {
                throw new RuntimeException('FTP authentication succeeded, but the configured remote root is not accessible.');
            }
            return ['ok' => true, 'message' => 'FTP connection and authentication succeeded.'];
        } finally {
            ftp_close($connection);
        }
    }

    /** @param resource $stream */
    public function upload(string $relativePath, $stream, int $size, string $mime): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('FTP upload requires a readable stream.');
        }
        $connection = $this->connect();
        try {
            $remote = $this->remotePath($relativePath);
            $this->ensureDirectories($connection, dirname($remote));
            rewind($stream);
            if (!@ftp_fput($connection, $remote, $stream, FTP_BINARY)) {
                throw new RuntimeException('FTP upload failed for ' . $relativePath . '.');
            }
        } finally {
            ftp_close($connection);
        }
    }

    public function delete(string $relativePath): void
    {
        $connection = $this->connect();
        try {
            @ftp_delete($connection, $this->remotePath($relativePath));
        } finally {
            ftp_close($connection);
        }
    }

    /** @return resource */
    private function connect()
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('FTP host, username and password are required.');
        }
        $port = (int) ($this->config['port'] ?? 21);
        $tls = (bool) ($this->config['tls'] ?? false);
        $connection = $tls && function_exists('ftp_ssl_connect')
            ? @ftp_ssl_connect($host, $port, 20)
            : @ftp_connect($host, $port, 20);
        if ($connection === false) {
            throw new RuntimeException('Unable to connect to the configured FTP server.');
        }
        if (!@ftp_login($connection, $username, $password)) {
            ftp_close($connection);
            throw new RuntimeException('FTP authentication failed.');
        }
        @ftp_pasv($connection, (bool) ($this->config['passive'] ?? true));
        return $connection;
    }

    /** @param resource $connection */
    private function ensureDirectories($connection, string $directory): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/.');
        if ($directory === '') {
            return;
        }
        $current = '';
        foreach (explode('/', $directory) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            @ftp_mkdir($connection, $current);
        }
    }

    private function remotePath(string $relativePath): string
    {
        $root = trim((string) ($this->config['remoteRoot'] ?? ''), '/');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        return '/' . ($root === '' ? $relativePath : $root . '/' . $relativePath);
    }
}
