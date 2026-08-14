<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Repository;

use Illuminate\Support\Facades\DB;

final class GoogleBooksDeliveryRepository
{
    public function get(int $contextId, string $transportKey, string $path): ?object
    {
        return DB::table('google_books_delivery_files')
            ->where('context_id', $contextId)
            ->where('transport_key', $transportKey)
            ->where('path_hash', hash('sha256', $path))
            ->first();
    }

    /** @return object[] */
    public function listByTransport(int $contextId, string $transportKey): array
    {
        return DB::table('google_books_delivery_files')
            ->where('context_id', $contextId)
            ->where('transport_key', $transportKey)
            ->get()
            ->all();
    }

    public function markSuccess(
        int $contextId,
        string $transportKey,
        string $path,
        string $fingerprint,
        int $size,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $pathHash = hash('sha256', $path);
        $existing = $this->get($contextId, $transportKey, $path);
        $data = [
            'context_id' => $contextId,
            'transport_key' => $transportKey,
            'path_hash' => $pathHash,
            'remote_path' => $path,
            'fingerprint' => $fingerprint,
            'file_size' => $size,
            'status' => 'delivered',
            'last_error' => null,
            'delivered_at' => $now,
            'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('google_books_delivery_files')->where('delivery_file_id', (int) $existing->delivery_file_id)->update($data);
            return;
        }
        $data['created_at'] = $now;
        DB::table('google_books_delivery_files')->insert($data);
    }

    public function markError(
        int $contextId,
        string $transportKey,
        string $path,
        string $fingerprint,
        int $size,
        string $error,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $pathHash = hash('sha256', $path);
        $existing = $this->get($contextId, $transportKey, $path);
        $data = [
            'context_id' => $contextId,
            'transport_key' => $transportKey,
            'path_hash' => $pathHash,
            'remote_path' => $path,
            'fingerprint' => $fingerprint,
            'file_size' => $size,
            'status' => 'error',
            'last_error' => $this->truncate($error),
            'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('google_books_delivery_files')->where('delivery_file_id', (int) $existing->delivery_file_id)->update($data);
            return;
        }
        $data['created_at'] = $now;
        $data['delivered_at'] = null;
        DB::table('google_books_delivery_files')->insert($data);
    }

    public function forget(int $deliveryFileId): void
    {
        DB::table('google_books_delivery_files')->where('delivery_file_id', $deliveryFileId)->delete();
    }

    private function truncate(string $message): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 4000, 'UTF-8');
        }
        return substr($message, 0, 4000);
    }
}
