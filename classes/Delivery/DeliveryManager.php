<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksDeliveryRepository;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use RuntimeException;
use Throwable;

final class DeliveryManager
{
    private GoogleBooksDeliveryRepository $repository;

    public function __construct(private GoogleBooksPlugin $plugin, ?GoogleBooksDeliveryRepository $repository = null)
    {
        $this->repository = $repository ?? new GoogleBooksDeliveryRepository();
    }

    /** @return array{ready:bool,reasons:array<int,string>,mode:string} */
    public function readiness(int $contextId): array
    {
        $reasons = [];
        try {
            $config = DeliveryConfig::forContext($this->plugin, $contextId);
        } catch (Throwable $e) {
            return ['ready' => false, 'reasons' => [$this->safeError($e)], 'mode' => DeliveryConfig::mode($this->plugin, $contextId)];
        }
        $mode = (string) $config['mode'];
        if (!(bool) $config['enabled']) {
            $reasons[] = 'Google delivery is disabled.';
        }
        if ($this->plugin->getCollectionCodes($contextId) === []) {
            $reasons[] = 'At least one valid Google collection code is required.';
        }
        if (!$config['deliverOnixFull'] && !$config['deliverOnixRights'] && !$config['deliverEbooks'] && !$config['deliverValidation']) {
            $reasons[] = 'Select at least one metadata/content payload.';
        }

        if ($mode === DeliveryConfig::HTTP_PULL) {
            if (!preg_match('/^[A-Za-z0-9]+$/', (string) ($config['username'] ?? '')) || (string) ($config['passwordHash'] ?? '') === '') {
                $reasons[] = 'HTTP/HTTPS pull requires the Google crawler username and password.';
            }
        } elseif (in_array($mode, [DeliveryConfig::GOOGLE_SFTP, DeliveryConfig::PUBLISHER_SFTP], true)) {
            if (($config['host'] ?? '') === '' || ($config['username'] ?? '') === '') {
                $reasons[] = 'SFTP host and username are required.';
            }
            if (($config['authMode'] ?? 'password') === 'private_key') {
                if (($config['privateKey'] ?? '') === '') {
                    $reasons[] = 'SFTP private-key authentication is selected but no private key is stored.';
                }
            } elseif (($config['password'] ?? '') === '') {
                $reasons[] = 'SFTP password authentication is selected but no password is stored.';
            }
        } elseif ($mode === DeliveryConfig::PUBLISHER_FTP) {
            if (($config['host'] ?? '') === '' || ($config['username'] ?? '') === '' || ($config['password'] ?? '') === '') {
                $reasons[] = 'FTP host, username and password are required.';
            }
        } elseif ($mode === DeliveryConfig::GCS) {
            if (($config['bucket'] ?? '') === '' || ($config['serviceAccountJson'] ?? '') === '') {
                $reasons[] = 'Google Cloud Storage requires a bucket and publisher writer service-account JSON.';
            }
        }

        $caps = TransportCapabilities::detect();
        $capabilityMap = [
            DeliveryConfig::GOOGLE_SFTP => 'googleSftpDropbox',
            DeliveryConfig::PUBLISHER_SFTP => 'publisherSftp',
            DeliveryConfig::PUBLISHER_FTP => 'publisherFtp',
            DeliveryConfig::GCS => 'gcs',
            DeliveryConfig::LOCAL_EXPORT => 'localExport',
        ];
        if (isset($capabilityMap[$mode]) && !($caps[$capabilityMap[$mode]] ?? false)) {
            $reasons[] = 'The current PHP runtime does not provide the protocol/extensions required by the selected delivery mode.';
        }
        if ($mode === DeliveryConfig::PUBLISHER_FTP && ($config['tls'] ?? false) && !($caps['publisherFtps'] ?? false)) {
            $reasons[] = 'FTPS/TLS is enabled but the current PHP runtime does not provide ftp_ssl_connect().' ;
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'mode' => $mode];
    }

    /** @return array<string,mixed> */
    public function test(object $context): array
    {
        $contextId = (int) $context->getId();
        $config = DeliveryConfig::forContext($this->plugin, $contextId);
        $mode = (string) $config['mode'];
        $started = microtime(true);
        try {
            if ($mode === DeliveryConfig::HTTP_PULL) {
                $result = [
                    'ok' => true,
                    'message' => 'HTTP/HTTPS pull uses the OMP virtual feed. The last external Basic-Auth request is reported separately in the authentication diagnostic.',
                ];
            } else {
                $transport = $this->transport($contextId, $config);
                $result = $transport->test();
            }
            $diagnostic = [
                'timestamp' => gmdate('c'),
                'status' => 'success',
                'mode' => $mode,
                'message' => (string) ($result['message'] ?? 'Connection test succeeded.'),
                'durationMs' => (int) round((microtime(true) - $started) * 1000),
            ];
            $this->storeDiagnostic($contextId, 'deliveryConnectionDiagnostic', $diagnostic);
            return $diagnostic;
        } catch (Throwable $e) {
            $diagnostic = [
                'timestamp' => gmdate('c'),
                'status' => 'failed',
                'mode' => $mode,
                'message' => $this->safeError($e),
                'durationMs' => (int) round((microtime(true) - $started) * 1000),
            ];
            $this->storeDiagnostic($contextId, 'deliveryConnectionDiagnostic', $diagnostic);
            return $diagnostic;
        }
    }

    /**
     * @return array{status:string,mode:string,total:int,uploaded:int,skipped:int,failed:int,deleted:int,bytes:int,errors:array<int,string>}
     */
    public function deliver(object $context, bool $force = false): array
    {
        $contextId = (int) $context->getId();
        $readiness = $this->readiness($contextId);
        if (!$readiness['ready']) {
            throw new RuntimeException(implode(' ', $readiness['reasons']));
        }
        $config = DeliveryConfig::forContext($this->plugin, $contextId);
        $mode = (string) $config['mode'];
        if ($mode === DeliveryConfig::HTTP_PULL) {
            $result = [
                'status' => 'ready_for_pull', 'mode' => $mode, 'total' => 0, 'uploaded' => 0,
                'skipped' => 0, 'failed' => 0, 'deleted' => 0, 'bytes' => 0, 'errors' => [],
            ];
            $this->storeDeliveryDiagnostic($contextId, $result);
            return $result;
        }

        $manifest = (new DeliveryManifestService($this->plugin))->build($context);
        $transport = $this->transport($contextId, $config);
        $transportKey = DeliveryConfig::transportKey($config);
        $result = [
            'status' => 'completed',
            'mode' => $mode,
            'total' => count($manifest),
            'uploaded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'deleted' => 0,
            'bytes' => 0,
            'errors' => [],
        ];

        foreach ($manifest as $path => $item) {
            $fingerprint = (string) $item['fingerprint'];
            $existing = $this->repository->get($contextId, $transportKey, $path);
            if (!$force && $existing && (string) $existing->status === 'delivered' && hash_equals((string) $existing->fingerprint, $fingerprint)) {
                $result['skipped']++;
                continue;
            }

            $stream = null;
            try {
                $stream = $this->openStream($item);
                $transport->upload($path, $stream, (int) $item['size'], (string) $item['mime']);
                $this->repository->markSuccess($contextId, $transportKey, $path, $fingerprint, (int) $item['size']);
                $result['uploaded']++;
                $result['bytes'] += (int) $item['size'];
            } catch (Throwable $e) {
                $result['failed']++;
                $message = $path . ': ' . $this->safeError($e);
                $result['errors'][] = $message;
                $this->repository->markError($contextId, $transportKey, $path, $fingerprint, (int) $item['size'], $message);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        // Do not remove the last known-good remote tree if this run was only
        // partially successful. For inventory-style transports, prune only
        // plugin-managed paths after the complete current manifest succeeded.
        if ($result['failed'] === 0 && $this->supportsCleanup($mode)) {
            $current = array_fill_keys(array_keys($manifest), true);
            foreach ($this->repository->listByTransport($contextId, $transportKey) as $state) {
                $oldPath = (string) $state->remote_path;
                if (isset($current[$oldPath])) {
                    continue;
                }
                try {
                    $transport->delete($oldPath);
                    $this->repository->forget((int) $state->delivery_file_id);
                    $result['deleted']++;
                } catch (Throwable $e) {
                    $result['errors'][] = 'Cleanup ' . $oldPath . ': ' . $this->safeError($e);
                }
            }
        }

        if ($result['failed'] > 0) {
            $result['status'] = 'completed_with_errors';
        }
        $this->storeDeliveryDiagnostic($contextId, $result);
        return $result;
    }

    private function transport(int $contextId, array $config): object
    {
        return match ((string) $config['mode']) {
            DeliveryConfig::GOOGLE_SFTP, DeliveryConfig::PUBLISHER_SFTP => new SftpTransport($config),
            DeliveryConfig::PUBLISHER_FTP => new FtpTransport($config),
            DeliveryConfig::GCS => new GoogleCloudStorageTransport($config),
            DeliveryConfig::LOCAL_EXPORT => new LocalExportTransport($contextId),
            default => throw new RuntimeException('The selected Google Books delivery transport does not push files.'),
        };
    }

    /** @param array<string,mixed> $item @return resource */
    private function openStream(array $item)
    {
        if (($item['kind'] ?? '') === 'inline') {
            $stream = fopen('php://temp', 'w+b');
            if (!is_resource($stream)) {
                throw new RuntimeException('Unable to create a temporary delivery stream.');
            }
            fwrite($stream, (string) $item['content']);
            rewind($stream);
            return $stream;
        }

        $asset = $item['asset'] ?? [];
        if (($asset['kind'] ?? '') === 'cover') {
            $stream = fopen((string) ($asset['path'] ?? ''), 'rb');
        } else {
            $stream = app()->get('file')->fs->readStream((string) ($asset['path'] ?? ''));
        }
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open the source OMP file for delivery.');
        }
        return $stream;
    }

    private function supportsCleanup(string $mode): bool
    {
        return in_array($mode, [
            DeliveryConfig::PUBLISHER_SFTP,
            DeliveryConfig::PUBLISHER_FTP,
            DeliveryConfig::GCS,
            DeliveryConfig::LOCAL_EXPORT,
        ], true);
    }

    /** @param array<string,mixed> $result */
    private function storeDeliveryDiagnostic(int $contextId, array $result): void
    {
        $payload = [
            'timestamp' => gmdate('c'),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'mode' => (string) ($result['mode'] ?? ''),
            'total' => (int) ($result['total'] ?? 0),
            'uploaded' => (int) ($result['uploaded'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'bytes' => (int) ($result['bytes'] ?? 0),
            'errors' => array_slice(array_map(fn ($error) => $this->safeText((string) $error), $result['errors'] ?? []), 0, 20),
        ];
        $this->storeDiagnostic($contextId, 'deliveryDiagnostic', $payload);
    }

    /** @param array<string,mixed> $payload */
    private function storeDiagnostic(int $contextId, string $setting, array $payload): void
    {
        try {
            $this->plugin->updateSetting(
                $contextId,
                $setting,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'string',
            );
        } catch (Throwable) {
        }
    }

    private function safeError(Throwable $error): string
    {
        return $this->safeText($error->getMessage());
    }

    private function safeText(string $value): string
    {
        // Avoid accidentally persisting URL userinfo or Basic/Bearer material in
        // diagnostics returned by low-level libraries.
        $value = preg_replace('/(Authorization\\s*:\\s*(?:Basic|Bearer))\\s+[^\\s]+/i', '$1 [redacted]', $value) ?? $value;
        $value = preg_replace('#(s?ftp://)[^/@\\s]+@#i', '$1[redacted]@', $value) ?? $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1200, 'UTF-8');
        }
        return substr($value, 0, 1200);
    }
}
