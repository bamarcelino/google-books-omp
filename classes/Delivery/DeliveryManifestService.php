<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Delivery;

use APP\plugins\generic\googleBooks\classes\Feed\FeedManifestService;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;

final class DeliveryManifestService
{
    private FeedManifestService $feed;

    public function __construct(private GoogleBooksPlugin $plugin)
    {
        $this->feed = new FeedManifestService($plugin);
    }

    /**
     * Build the complete current export tree that an automated Google transport
     * should expose or upload. No secret or transport configuration is included.
     *
     * @return array<string,array<string,mixed>> keyed by relative path
     */
    public function build(object $context): array
    {
        $contextId = (int) $context->getId();
        $revision = (int) $this->plugin->getFeedRevision($contextId);
        $filename = 'googlebooks' . gmdate('Ymd\\THis\\Z', $revision) . '.xml';
        $items = [];

        if ($this->plugin->boolSetting($contextId, 'deliverValidation', true)) {
            $submissionId = (int) $this->plugin->getSetting($contextId, 'validationSubmissionId');
            if ($submissionId > 0) {
                $xml = $this->feed->buildValidationOnix($context, $submissionId);
                $path = 'onix/validate/googlebooksvalidation' . $submissionId . '.xml';
                $items[$path] = $this->inlineItem($path, $xml, 'application/xml', $revision, 'validation');
            }
        }

        foreach ($this->plugin->getCollectionCodes($contextId) as $code) {
            if ($this->plugin->boolSetting($contextId, 'deliverOnixFull', true)) {
                $xml = $this->feed->buildOnix($context, $code, false);
                $path = 'onix/' . $code . '-full/' . $filename;
                $items[$path] = $this->inlineItem($path, $xml, 'application/xml', $revision, 'onix_full');
            }

            if ($this->plugin->boolSetting($contextId, 'deliverOnixRights', true)) {
                $xml = $this->feed->buildOnix($context, $code, true);
                $path = 'onix/' . $code . '-rights/' . $filename;
                $items[$path] = $this->inlineItem($path, $xml, 'application/xml', $revision, 'onix_rights');
            }

            if ($this->plugin->boolSetting($contextId, 'deliverEbooks', true)) {
                foreach ($this->feed->assets($context, $code) as $name => $entry) {
                    $path = 'ebooks/' . $code . '/' . $name;
                    $asset = $entry['asset'];
                    $fingerprintPayload = [
                        'path' => (string) ($asset['path'] ?? ''),
                        'size' => (int) ($asset['size'] ?? 0),
                        'modified' => (int) ($entry['modified'] ?? 0),
                        'mime' => (string) ($asset['mime'] ?? ''),
                        'filename' => (string) ($asset['filename'] ?? $name),
                        'fileId' => (int) ($asset['fileId'] ?? 0),
                        'formatId' => (int) ($asset['formatId'] ?? 0),
                    ];
                    $items[$path] = [
                        'path' => $path,
                        'kind' => 'asset',
                        'category' => 'ebook',
                        'asset' => $asset,
                        'mime' => (string) ($asset['mime'] ?? 'application/octet-stream'),
                        'size' => (int) ($asset['size'] ?? 0),
                        'modified' => (int) ($entry['modified'] ?? 0),
                        'fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                    ];
                }
            }
        }

        ksort($items);
        return $items;
    }

    /** @return array<string,mixed> */
    private function inlineItem(string $path, string $content, string $mime, int $modified, string $category): array
    {
        return [
            'path' => $path,
            'kind' => 'inline',
            'category' => $category,
            'content' => $content,
            'mime' => $mime,
            'size' => strlen($content),
            'modified' => $modified,
            'fingerprint' => hash('sha256', $content),
        ];
    }
}
