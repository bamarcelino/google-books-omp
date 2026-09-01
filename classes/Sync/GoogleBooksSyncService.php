<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Sync;

use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Api\GoogleBooksApiClient;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixValidator;
use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use Throwable;

final class GoogleBooksSyncService
{
    private OmpBookMapper $mapper;
    private GoogleBooksStateRepository $repository;
    private GoogleOnixValidator $validator;

    public function __construct(
        private GoogleBooksPlugin $plugin,
        ?OmpBookMapper $mapper = null,
        ?GoogleBooksStateRepository $repository = null,
        ?GoogleOnixValidator $validator = null,
    ) {
        $this->mapper = $mapper ?? new OmpBookMapper();
        $this->repository = $repository ?? new GoogleBooksStateRepository();
        $this->validator = $validator ?? new GoogleOnixValidator();
    }

    /**
     * Discover Google Books records by ISBN without touching the publisher feed.
     *
     * This intentionally accepts historical OMP publications that are not
     * currently feed-eligible. A missing proof file, price, sales-rights row,
     * collection code or disabled Google feed must never prevent discovery.
     *
     * @return array{products:int,linked:int,notFound:int,ambiguous:int,retryable:int,failed:int,skipped:int,details:array<int,string>,errors:array<int,string>}
     */
    public function discoverSubmission(Submission $submission, object $context): array
    {
        $result = [
            'products' => 0,
            'linked' => 0,
            'notFound' => 0,
            'ambiguous' => 0,
            'retryable' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
            'errors' => [],
        ];

        $books = $this->mapper->mapDiscoverySubmission($submission, $context);
        $result['products'] = count($books);
        if ($books === []) {
            // A published monograph with no valid ISBN is not a Google API
            // failure. Record it as skipped so large legacy catalogues do not
            // misleadingly report hundreds of errors.
            $result['skipped'] = 1;
            return $result;
        }

        $apiKey = trim($this->plugin->getGoogleApiKey((int) $context->getId()));
        if ($apiKey === '') {
            foreach ($books as $book) {
                $this->repository->ensureRecord($book);
            }
            $result['failed'] = count($books);
            $result['errors'][] = 'A Google Books API key is required for discovery.';
            return $result;
        }

        $apiClient = new GoogleBooksApiClient(
            $apiKey,
            $this->nullableSetting((int) $context->getId(), 'googlePartnerId'),
        );

        foreach ($books as $book) {
            try {
                $this->repository->ensureRecord($book);
                $match = $apiClient->findByIsbn($book->isbn13, $book->title);
                $this->repository->upsertDiscovery($book, $match);
                if ($match->found) {
                    $result['linked']++;
                } elseif ($match->ambiguous) {
                    $result['ambiguous']++;
                    $result['failed']++;
                    $result['errors'][] = $book->isbn13 . ': multiple exact Google Books Volume IDs use this normalized ISBN; automatic public linking was withheld.';
                } else {
                    $result['notFound']++;
                    $result['retryable']++;
                    $result['details'][] = $book->isbn13
                        . ': no exact Volume returned after the Books API ISBN lookup, public Google Books ISBN resolver, ISBN-10 fallback and configured Partner lookup.';
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['retryable']++;
                $reason = trim($e->getMessage());
                if ($reason === '') {
                    $reason = get_class($e) . ' returned no diagnostic message (code ' . $e->getCode() . ').';
                }
                $message = $book->isbn13 . ': Google Books discovery failed - ' . $reason;
                $result['errors'][] = $message;
                try {
                    $this->repository->markDiscoveryError($book, $message);
                } catch (Throwable) {
                    // Do not allow diagnostic persistence to abort the remaining
                    // ISBNs in a catalogue batch.
                }
            }
        }

        return $result;
    }

    /** Backwards-compatible name used by queued 0.1.0.x verification jobs. */
    public function verifySubmission(Submission $submission, object $context): array
    {
        return $this->discoverSubmission($submission, $context);
    }

    /**
     * Synchronize one OMP monograph to the automated Google publisher feed.
     * Google Books API discovery is deliberately not performed here.
     *
     * @return array{products:int,updated:int,unchanged:int,retired:int,feedIneligible:int,failed:int,skipped:int,feedChanged:bool,errors:array<int,string>}
     */
    public function syncSubmission(Submission $submission, object $context, bool $force = false): array
    {
        $result = [
            'products' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'retired' => 0,
            'feedIneligible' => 0,
            'failed' => 0,
            'skipped' => 0,
            'feedChanged' => false,
            'errors' => [],
        ];

        $contextId = (int) $context->getId();
        $defaultFree = $this->plugin->boolSetting($contextId, 'defaultFreeOfCharge', false);
        $defaultWorldwideRights = $this->plugin->boolSetting($contextId, 'defaultWorldwideRightsForFree', false);

        // Reconcile against every current ISBN, not only feed-eligible eISBNs.
        // This prevents discovery-only print ISBN records from being retired.
        $discoveryBooks = $this->mapper->mapDiscoverySubmission($submission, $context);
        $currentIsbns = array_map(static fn ($book): string => $book->isbn13, $discoveryBooks);
        $result['retired'] = $this->repository->retireMissingProducts(
            $contextId,
            (int) $submission->getId(),
            $currentIsbns,
        );
        $result['feedChanged'] = $result['retired'] > 0;

        if ($discoveryBooks === []) {
            $result['skipped'] = 1;
            return $result;
        }

        $feedBooks = $this->mapper->mapSubmission($submission, $context, $defaultFree, $defaultWorldwideRights, true);
        $feedByIsbn = [];
        foreach ($feedBooks as $book) {
            $feedByIsbn[$book->isbn13] = $book;
        }
        $result['products'] = count($feedBooks);

        // ISBNs can be perfectly valid for API discovery while not being valid
        // automated-feed products (for example print ISBNs or legacy formats
        // without a current whole-book proof). This is expected, not an error.
        foreach ($discoveryBooks as $identityBook) {
            $this->repository->ensureRecord($identityBook);
            if (!isset($feedByIsbn[$identityBook->isbn13])) {
                $result['feedIneligible']++;
                if ($this->repository->markFeedIneligible(
                    $identityBook,
                    'No eligible current PDF/EPUB publication format and whole-book proof file was found for this ISBN.'
                )) {
                    $result['feedChanged'] = true;
                }
            }
        }

        foreach ($feedBooks as $book) {
            try {
                $collectionCode = $this->plugin->getCollectionCodeForBook($contextId, $book);
                if (!GoogleBooksPlugin::isValidCollectionCode($collectionCode)) {
                    $result['feedIneligible']++;
                    $message = $book->isbn13 . ': no valid Google collection code is configured for this book/imprint.';
                    $result['errors'][] = $message;
                    if ($this->repository->markFeedIneligible($book, $message)) {
                        $result['feedChanged'] = true;
                    }
                    continue;
                }

                $validationErrors = $this->validator->validateBook($book);
                if ($validationErrors !== []) {
                    $result['feedIneligible']++;
                    $message = $book->isbn13 . ': ' . implode(' ', $validationErrors);
                    $result['errors'][] = $message;
                    if ($this->repository->markFeedIneligible($book, $message)) {
                        $result['feedChanged'] = true;
                    }
                    continue;
                }

                $rightsErrors = $this->validator->validateRightsBook($book);
                if ($rightsErrors !== []) {
                    $result['feedIneligible']++;
                    $message = $book->isbn13 . ': ' . implode(' ', $rightsErrors);
                    $result['errors'][] = $message;
                    if ($this->repository->markFeedIneligible($book, $message)) {
                        $result['feedChanged'] = true;
                    }
                    continue;
                }

                if ($this->repository->markPrepared($book, $force)) {
                    $result['updated']++;
                    $result['feedChanged'] = true;
                } else {
                    $result['unchanged']++;
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $message = $book->isbn13 . ': feed synchronization error - ' . $e->getMessage();
                $result['errors'][] = $message;
                try {
                    if ($this->repository->markFeedError($book, $message)) {
                        $result['feedChanged'] = true;
                    }
                } catch (Throwable) {
                }
            }
        }

        return $result;
    }

    /**
     * Synchronous compatibility helper. The dashboard uses the batched
     * CatalogDiscoveryJob for large catalogues.
     *
     * @return array{scanned:int,linked:int,notFound:int,retryable:int,updated:int,unchanged:int,retired:int,failed:int,skipped:int,feedIneligible:int}
     */
    public function verifyCatalog(object $context, ?int $userId = null): array
    {
        return $this->discoverCatalog($context, $userId);
    }

    /**
     * @return array{scanned:int,linked:int,notFound:int,retryable:int,updated:int,unchanged:int,retired:int,failed:int,skipped:int,feedIneligible:int}
     */
    public function discoverCatalog(object $context, ?int $userId = null): array
    {
        $contextId = (int) $context->getId();
        $runId = $this->repository->createRun($contextId, 'discovery', $userId);
        $counters = $this->emptyCounters();
        $errors = [];

        try {
            $submissions = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany();
            foreach ($submissions as $submission) {
                $counters['scanned']++;
                $result = $this->discoverSubmission($submission, $context);
                foreach (['linked', 'notFound', 'retryable', 'failed', 'skipped'] as $key) {
                    $counters[$key] += $result[$key];
                }
                foreach (($result['details'] ?? []) as $detail) {
                    $detail = trim((string) $detail);
                    $errors[] = 'Submission ' . $submission->getId() . ': '
                        . ($detail !== '' ? $detail : 'Discovery completed without a diagnostic detail.');
                }
                foreach ($result['errors'] as $error) {
                    $error = trim((string) $error);
                    $errors[] = 'Submission ' . $submission->getId() . ': '
                        . ($error !== '' ? $error : 'Discovery failed without a diagnostic message.');
                }
            }
            $status = $counters['failed'] > 0 ? 'completed_with_errors' : 'completed';
            $this->repository->finishRun($runId, $status, $counters, $errors ? implode("\n", $errors) : null);
            return $counters;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $this->repository->finishRun($runId, 'failed', $counters, implode("\n", $errors));
            throw $e;
        }
    }

    /**
     * Synchronize every published monograph in a press to the feed only.
     *
     * @return array{scanned:int,linked:int,notFound:int,retryable:int,updated:int,unchanged:int,retired:int,failed:int,skipped:int,feedIneligible:int}
     */
    public function syncCatalog(object $context, bool $force = false, ?int $userId = null): array
    {
        $contextId = (int) $context->getId();
        $runId = $this->repository->createRun($contextId, $force ? 'force_feed' : 'feed', $userId);
        $counters = $this->emptyCounters();
        $errors = [];
        $publishedSubmissionIds = [];
        $feedChanged = false;

        try {
            $submissions = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany();

            foreach ($submissions as $submission) {
                $publishedSubmissionIds[] = (int) $submission->getId();
                $counters['scanned']++;
                try {
                    $result = $this->syncSubmission($submission, $context, $force);
                    foreach (['updated', 'unchanged', 'retired', 'failed', 'skipped', 'feedIneligible'] as $key) {
                        $counters[$key] += $result[$key];
                    }
                    $feedChanged = $feedChanged || $result['feedChanged'];
                    foreach ($result['errors'] as $error) {
                        $errors[] = 'Submission ' . $submission->getId() . ': ' . $error;
                    }
                } catch (Throwable $e) {
                    $counters['failed']++;
                    $errors[] = 'Submission ' . $submission->getId() . ': ' . $e->getMessage();
                }
            }

            $orphaned = $this->repository->retireMissingSubmissions($contextId, $publishedSubmissionIds);
            if ($orphaned > 0) {
                $counters['retired'] += $orphaned;
                $feedChanged = true;
            }
            if ($force || $feedChanged) {
                $this->plugin->bumpFeedRevision($contextId);
            }

            $status = $counters['failed'] > 0 ? 'completed_with_errors' : 'completed';
            $this->repository->finishRun($runId, $status, $counters, $errors ? implode("\n", $errors) : null);
            return $counters;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $this->repository->finishRun($runId, 'failed', $counters, implode("\n", $errors));
            throw $e;
        }
    }

    /** @return array{scanned:int,linked:int,notFound:int,retryable:int,updated:int,unchanged:int,retired:int,failed:int,skipped:int,feedIneligible:int} */
    private function emptyCounters(): array
    {
        return [
            'scanned' => 0,
            'linked' => 0,
            'notFound' => 0,
            'retryable' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'retired' => 0,
            'failed' => 0,
            'skipped' => 0,
            'feedIneligible' => 0,
        ];
    }

    private function nullableSetting(int $contextId, string $name): ?string
    {
        $value = trim((string) $this->plugin->getSetting($contextId, $name));
        return $value !== '' ? $value : null;
    }
}
