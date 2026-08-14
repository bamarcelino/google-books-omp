<?php

declare(strict_types=1);

/**
 * Authenticated virtual HTTP feed consumed by Google automated content fetching.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Feed;

use APP\core\Application;
use APP\plugins\generic\googleBooks\classes\Delivery\DeliveryConfig;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FeedHandler extends \APP\handler\Handler
{
    public function __construct(private GoogleBooksPlugin $plugin)
    {
        parent::__construct();
        $this->setEnforceRestrictedSite(false);
    }

    public function index($args, $request): void
    {
        $context = $this->requireContext($request);
        $this->authenticate($context);
        $this->directory([
            ['name' => 'onix/', 'href' => $this->feedUrl($request, 'onix')],
            ['name' => 'ebooks/', 'href' => $this->feedUrl($request, 'ebooks')],
        ]);
    }

    public function onix($args, $request): void
    {
        $context = $this->requireContext($request);
        $this->authenticate($context);
        $service = new FeedManifestService($this->plugin);
        $args = array_values(array_filter(array_map('strval', $args), static fn (string $v): bool => $v !== ''));

        if ($args === []) {
            $entries = [[
                'name' => 'validate/',
                'href' => $this->feedUrl($request, 'onix', ['validate']),
            ]];
            if ($this->feedEnabled($context)) {
                foreach ($this->plugin->getCollectionCodes((int) $context->getId()) as $code) {
                    $entries[] = [
                        'name' => $code . '-full/',
                        'href' => $this->feedUrl($request, 'onix', [$code . '-full']),
                    ];
                    $entries[] = [
                        'name' => $code . '-rights/',
                        'href' => $this->feedUrl($request, 'onix', [$code . '-rights']),
                    ];
                }
            }
            $this->directory($entries);
        }

        if ($args[0] === 'validate') {
            $submissionId = (int) $this->plugin->getSetting((int) $context->getId(), 'validationSubmissionId');
            if (!$submissionId) {
                throw new NotFoundHttpException('No validation monograph selected.');
            }
            $filename = 'googlebooksvalidation' . $submissionId . '.xml';
            if (count($args) === 1) {
                $xml = $service->buildValidationOnix($context, $submissionId);
                $this->directory([[
                    'name' => $filename,
                    'href' => $this->feedUrl($request, 'onix', ['validate', $filename]),
                    'size' => strlen($xml),
                    'modified' => (int) $this->plugin->getFeedRevision((int) $context->getId()),
                ]]);
            }
            if (count($args) === 2 && hash_equals($filename, $args[1])) {
                $xml = $service->buildValidationOnix($context, $submissionId);
                $this->xml($xml, (int) $this->plugin->getFeedRevision((int) $context->getId()));
            }
            throw new NotFoundHttpException();
        }

        if (!$this->feedEnabled($context)) {
            throw new NotFoundHttpException('Google Books feed is not enabled.');
        }

        if (!preg_match('/^([A-Za-z0-9]{7})-(full|rights)$/i', $args[0], $matches)) {
            throw new NotFoundHttpException();
        }
        $collectionCode = strtoupper($matches[1]);
        $profile = strtolower($matches[2]);
        if (!in_array($collectionCode, $this->plugin->getCollectionCodes((int) $context->getId()), true)) {
            throw new NotFoundHttpException();
        }

        $revision = (int) $this->plugin->getFeedRevision((int) $context->getId());
        $filename = 'googlebooks' . gmdate('Ymd\THis\Z', $revision) . '.xml';
        if (count($args) === 1) {
            $xml = $service->buildOnix($context, $collectionCode, $profile === 'rights');
            $this->directory([[
                'name' => $filename,
                'href' => $this->feedUrl($request, 'onix', [$args[0], $filename]),
                'size' => strlen($xml),
                'modified' => $revision,
            ]]);
        }
        if (count($args) === 2 && hash_equals($filename, $args[1])) {
            $xml = $service->buildOnix($context, $collectionCode, $profile === 'rights');
            $this->xml($xml, $revision);
        }
        throw new NotFoundHttpException();
    }

    public function ebooks($args, $request): void
    {
        $context = $this->requireContext($request);
        $this->authenticate($context);
        if (!$this->feedEnabled($context)) {
            throw new NotFoundHttpException('Google Books feed is not enabled.');
        }

        $args = array_values(array_filter(array_map('strval', $args), static fn (string $v): bool => $v !== ''));
        $codes = $this->plugin->getCollectionCodes((int) $context->getId());
        if ($args === []) {
            $entries = [];
            foreach ($codes as $code) {
                $entries[] = [
                    'name' => $code . '/',
                    'href' => $this->feedUrl($request, 'ebooks', [$code]),
                ];
            }
            $this->directory($entries);
        }

        $collectionCode = strtoupper($args[0]);
        if (!preg_match('/^[A-Z0-9]{7}$/', $collectionCode) || !in_array($collectionCode, $codes, true)) {
            throw new NotFoundHttpException();
        }

        $service = new FeedManifestService($this->plugin);
        $assets = $service->assets($context, $collectionCode);
        if (count($args) === 1) {
            $entries = [];
            foreach ($assets as $filename => $entry) {
                $entries[] = [
                    'name' => $filename,
                    'href' => $this->feedUrl($request, 'ebooks', [$collectionCode, $filename]),
                    'size' => (int) $entry['asset']['size'],
                    'modified' => (int) $entry['modified'],
                ];
            }
            $this->directory($entries);
        }

        if (count($args) !== 2 || !isset($assets[$args[1]])) {
            throw new NotFoundHttpException();
        }
        $this->streamAsset($assets[$args[1]]['asset'], (int) $assets[$args[1]]['modified']);
    }

    private function requireContext($request): object
    {
        $context = $request->getContext();
        if (!$context) {
            throw new NotFoundHttpException();
        }
        return $context;
    }

    private function authenticate(object $context): void
    {
        $contextId = (int) $context->getId();
        $user = trim((string) $this->plugin->getSetting($contextId, 'feedUsername'));
        $hash = (string) $this->plugin->getSetting($contextId, 'feedPasswordHash');
        $diagnostic = BasicAuth::diagnostic($user, $hash);
        $authenticated = (bool) ($diagnostic['authenticated'] ?? false);
        $this->storeAuthDiagnostic($contextId, $diagnostic, $authenticated);
        if (!$authenticated) {
            BasicAuth::challenge();
        }
    }

    /** @param array<string,bool|string> $diagnostic */
    private function storeAuthDiagnostic(int $contextId, array $diagnostic, bool $authenticated): void
    {
        try {
            $payload = $diagnostic;
            $payload['timestamp'] = gmdate('c');
            $payload['status'] = $authenticated ? 'success' : 'failed';

            // Failed requests are rare and diagnostically relevant, so always
            // keep their timestamp current. For successful crawler requests,
            // avoid a database write on every file fetch once a success state
            // has already been recorded.
            if ($authenticated) {
                $existing = json_decode((string) $this->plugin->getSetting($contextId, 'feedAuthDiagnostic'), true);
                if (is_array($existing) && ($existing['status'] ?? '') === 'success') {
                    return;
                }
            }

            $this->plugin->updateSetting(
                $contextId,
                'feedAuthDiagnostic',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'string',
            );
        } catch (\Throwable) {
            // Authentication diagnostics must never interfere with feed access.
        }
    }

    /**
     * Build crawler URLs with the press primary locale. OMP 3.5 canonicalizes
     * multilingual page routes and redirects locale-less URLs; an explicit
     * primary locale keeps Google on a single stable authenticated URL.
     */
    private function feedUrl($request, ?string $op = null, ?array $path = null): string
    {
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context?->getPath(),
            GoogleBooksPlugin::FEED_PAGE,
            $op,
            $path,
            null,
            null,
            false,
            $context?->getPrimaryLocale(),
        );
    }

    private function feedEnabled(object $context): bool
    {
        $contextId = (int) $context->getId();
        return (bool) $this->plugin->getSetting($contextId, 'feedEnabled')
            && DeliveryConfig::mode($this->plugin, $contextId) === DeliveryConfig::HTTP_PULL;
    }

    /** @param array<int,array{name:string,href:string,size?:?int,modified?:?int}> $entries */
    private function directory(array $entries): never
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Google Books Feed</title></head><body>\n";
        echo "<table><thead><tr><th>Filename</th><th>Size</th><th>Last modified</th></tr></thead><tbody>\n";
        foreach ($entries as $entry) {
            $name = htmlspecialchars($entry['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $href = htmlspecialchars($entry['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $size = array_key_exists('size', $entry) && $entry['size'] !== null ? (string) $entry['size'] : '-';
            $modified = array_key_exists('modified', $entry) && $entry['modified'] ? gmdate('Y-m-d H:i:s \U\T\C', (int) $entry['modified']) : '-';
            echo '<tr><td><a href="' . $href . '">' . $name . '</a></td><td>' . $size . '</td><td>' . $modified . "</td></tr>\n";
        }
        echo "</tbody></table></body></html>\n";
        exit;
    }

    private function xml(string $xml, int $modified): never
    {
        $this->notModified($modified);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . strlen($xml));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('Cache-Control: private, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo $xml;
        exit;
    }

    /** @param array<string,mixed> $asset */
    private function streamAsset(array $asset, int $modified): never
    {
        $this->notModified($modified);
        header('Content-Type: ' . ($asset['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (int) $asset['size']);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('Content-Disposition: inline; filename="' . addcslashes((string) $asset['filename'], '"\\') . '"');
        header('Cache-Control: private, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        if (($asset['kind'] ?? '') === 'cover') {
            $stream = fopen((string) $asset['path'], 'rb');
        } else {
            $stream = app()->get('file')->fs->readStream((string) $asset['path']);
        }
        if (!is_resource($stream)) {
            throw new NotFoundHttpException();
        }
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    private function notModified(int $modified): void
    {
        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince !== '') {
            $clientTime = strtotime($ifModifiedSince);
            if ($clientTime !== false && $clientTime >= $modified) {
                header('HTTP/1.1 304 Not Modified');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
                header('Cache-Control: private, no-cache, must-revalidate');
                header('X-Robots-Tag: noindex, nofollow, noarchive');
                exit;
            }
        }
    }
}
