<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use PKP\config\Config;
use RuntimeException;

final class LocalExportTransport
{
    public function __construct(private int $contextId)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function test(): array
    {
        $root = $this->root();
        $this->ensureDirectory($root);
        if (!is_writable($root)) {
            throw new RuntimeException('The Google Books local export directory is not writable.');
        }
        return ['ok' => true, 'message' => 'The protected local Google Books export directory is writable.'];
    }

    /** @param resource $stream */
    public function upload(string $relativePath, $stream, int $size, string $mime): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Local export requires a readable stream.');
        }
        $target = $this->path($relativePath);
        $this->ensureDirectory(dirname($target));
        $output = fopen($target, 'wb');
        if (!is_resource($output)) {
            throw new RuntimeException('Unable to create local Google Books export file.');
        }
        try {
            rewind($stream);
            stream_copy_to_stream($stream, $output);
        } finally {
            fclose($output);
        }
    }

    public function delete(string $relativePath): void
    {
        $target = $this->path($relativePath);
        if (is_file($target)) {
            @unlink($target);
        }
    }

    public function root(): string
    {
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        if ($filesDir === '') {
            throw new RuntimeException('OMP files_dir is not configured.');
        }
        return $filesDir . DIRECTORY_SEPARATOR . 'googleBooksDelivery' . DIRECTORY_SEPARATOR . $this->contextId;
    }

    private function path(string $relativePath): string
    {
        $relativePath = trim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
        return $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the Google Books local export directory.');
        }
    }
}
