<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Feed;

final class BasicAuth
{
    /**
     * Resolve HTTP Basic Auth credentials across Apache, LiteSpeed, nginx and
     * PHP-FPM/FastCGI hosting layouts.
     *
     * The optional arguments exist for deterministic regression tests. Runtime
     * callers should use the zero-argument form.
     *
     * @param array<string,mixed>|null $server
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $environment
     * @return array{0:?string,1:?string}
     */
    public static function credentials(?array $server = null, ?array $headers = null, ?array $environment = null): array
    {
        $resolved = self::resolve($server, $headers, $environment);
        return [$resolved['user'], $resolved['password']];
    }

    /**
     * Return only non-secret authentication state suitable for an administrator
     * diagnostic panel. Username, password, Authorization value and password
     * hash are never returned.
     *
     * @param array<string,mixed>|null $server
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $environment
     * @return array<string,bool|string>
     */
    public static function diagnostic(
        string $expectedUser,
        string $passwordHash,
        ?array $server = null,
        ?array $headers = null,
        ?array $environment = null,
    ): array {
        $resolved = self::resolve($server, $headers, $environment);
        $user = $resolved['user'];
        $password = $resolved['password'];
        $configuredUserPresent = $expectedUser !== '';
        $configuredPasswordPresent = $passwordHash !== '';
        $usernamePresent = $user !== null && $user !== '';
        $passwordPresent = $password !== null && $password !== '';
        $usernameMatches = $usernamePresent && $configuredUserPresent && hash_equals($expectedUser, (string) $user);
        $passwordMatches = $passwordPresent && $configuredPasswordPresent && password_verify((string) $password, $passwordHash);

        return [
            'authenticated' => $usernameMatches && $passwordMatches,
            'credentialSource' => (string) $resolved['credentialSource'],
            'authorizationPresent' => (bool) $resolved['authorizationPresent'],
            'authorizationSource' => (string) $resolved['authorizationSource'],
            'authorizationIsBasic' => (bool) $resolved['authorizationIsBasic'],
            'authorizationDecoded' => (bool) $resolved['authorizationDecoded'],
            'nativeUserPresent' => (bool) $resolved['nativeUserPresent'],
            'nativePasswordPresent' => (bool) $resolved['nativePasswordPresent'],
            'usernamePresent' => $usernamePresent,
            'passwordPresent' => $passwordPresent,
            'configuredUsernamePresent' => $configuredUserPresent,
            'configuredPasswordHashPresent' => $configuredPasswordPresent,
            'usernameMatches' => $usernameMatches,
            'passwordMatches' => $passwordMatches,
        ];
    }

    public static function check(string $expectedUser, string $passwordHash): bool
    {
        return (bool) self::diagnostic($expectedUser, $passwordHash)['authenticated'];
    }

    /**
     * @param array<string,mixed>|null $server
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $environment
     * @return array<string,mixed>
     */
    private static function resolve(?array $server, ?array $headers, ?array $environment): array
    {
        $runtimeServer = $server === null;
        $runtimeHeaders = $headers === null;
        $runtimeEnvironment = $environment === null;
        $server ??= $_SERVER;
        $environment ??= $_ENV;

        $nativeUser = array_key_exists('PHP_AUTH_USER', $server) ? (string) $server['PHP_AUTH_USER'] : null;
        $nativePassword = array_key_exists('PHP_AUTH_PW', $server) ? (string) $server['PHP_AUTH_PW'] : null;
        [$authorization, $authorizationSource] = self::authorizationHeader(
            $server,
            $headers,
            $environment,
            $runtimeHeaders,
            $runtimeEnvironment,
        );
        $decoded = self::decodeBasicHeader($authorization);
        $authorizationIsBasic = $authorization !== '' && preg_match('/^Basic\s+/i', $authorization) === 1;

        // Native PHP auth variables are authoritative only when PHP received
        // both values. Some FastCGI stacks expose PHP_AUTH_USER while omitting
        // PHP_AUTH_PW; in that case continue to the Authorization header.
        if ($nativeUser !== null && $nativePassword !== null) {
            return [
                'user' => $nativeUser,
                'password' => $nativePassword,
                'credentialSource' => 'php_auth',
                'authorizationPresent' => $authorization !== '',
                'authorizationSource' => $authorizationSource,
                'authorizationIsBasic' => $authorizationIsBasic,
                'authorizationDecoded' => $decoded !== null,
                'nativeUserPresent' => true,
                'nativePasswordPresent' => true,
            ];
        }

        if ($decoded !== null) {
            return [
                'user' => $decoded[0],
                'password' => $decoded[1],
                'credentialSource' => $authorizationSource !== '' ? $authorizationSource : 'authorization',
                'authorizationPresent' => true,
                'authorizationSource' => $authorizationSource,
                'authorizationIsBasic' => true,
                'authorizationDecoded' => true,
                'nativeUserPresent' => $nativeUser !== null,
                'nativePasswordPresent' => $nativePassword !== null,
            ];
        }

        // Preserve compatibility when only PHP_AUTH_USER is made available.
        // A missing password will safely fail password_verify().
        if ($nativeUser !== null) {
            return [
                'user' => $nativeUser,
                'password' => $nativePassword ?? '',
                'credentialSource' => 'php_auth_user_only',
                'authorizationPresent' => $authorization !== '',
                'authorizationSource' => $authorizationSource,
                'authorizationIsBasic' => $authorizationIsBasic,
                'authorizationDecoded' => false,
                'nativeUserPresent' => true,
                'nativePasswordPresent' => $nativePassword !== null,
            ];
        }

        return [
            'user' => null,
            'password' => null,
            'credentialSource' => 'none',
            'authorizationPresent' => $authorization !== '',
            'authorizationSource' => $authorizationSource,
            'authorizationIsBasic' => $authorizationIsBasic,
            'authorizationDecoded' => false,
            'nativeUserPresent' => false,
            'nativePasswordPresent' => false,
        ];
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed> $environment
     * @return array{0:string,1:string}
     */
    private static function authorizationHeader(
        array $server,
        ?array $headers,
        array $environment,
        bool $useRuntimeHeaders,
        bool $useRuntimeEnvironment,
    ): array {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION', 'FCGI_HTTP_AUTHORIZATION'] as $key) {
            $value = trim((string) ($server[$key] ?? ''));
            if ($value !== '') {
                return [$value, 'server:' . $key];
            }
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION', 'FCGI_HTTP_AUTHORIZATION'] as $key) {
            $value = trim((string) ($environment[$key] ?? ''));
            if ($value !== '') {
                return [$value, 'env:' . $key];
            }
        }

        if ($useRuntimeEnvironment && function_exists('getenv')) {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION', 'FCGI_HTTP_AUTHORIZATION'] as $key) {
                $value = getenv($key);
                if ($value !== false && trim((string) $value) !== '') {
                    return [trim((string) $value), 'getenv:' . $key];
                }
            }
        }

        if ($headers !== null) {
            $value = self::headerValue($headers, 'Authorization');
            if ($value !== '') {
                return [$value, 'headers:provided'];
            }
        }

        if ($useRuntimeHeaders && function_exists('getallheaders')) {
            $value = getallheaders();
            if (is_array($value)) {
                $header = self::headerValue($value, 'Authorization');
                if ($header !== '') {
                    return [$header, 'headers:getallheaders'];
                }
            }
        }

        if ($useRuntimeHeaders && function_exists('apache_request_headers')) {
            $value = apache_request_headers();
            if (is_array($value)) {
                $header = self::headerValue($value, 'Authorization');
                if ($header !== '') {
                    return [$header, 'headers:apache_request_headers'];
                }
            }
        }

        return ['', ''];
    }

    /** @param array<string,mixed> $headers */
    private static function headerValue(array $headers, string $target): string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $target) !== 0) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @return array{0:string,1:string}|null */
    private static function decodeBasicHeader(string $header): ?array
    {
        if ($header === '' || !preg_match('/^Basic\s+([^\s]+)\s*$/i', $header, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$user, $password] = explode(':', $decoded, 2);
        return [$user, $password];
    }

    public static function challenge(): never
    {
        header('WWW-Authenticate: Basic realm="Google Books Publisher Feed"');
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Authentication required.';
        exit;
    }
}
