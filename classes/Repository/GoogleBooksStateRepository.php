<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Repository;

use APP\plugins\generic\googleBooks\classes\Discovery\GoogleBooksMatch;
use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GoogleBooksStateRepository
{
    public function ensureRecord(BookMetadata $book): object
    {
        $record = $this->findByIsbn($book->contextId, $book->isbn13);
        if (!$record) {
            $now = $this->now();
            DB::table('google_books_records')->insertOrIgnore([
                'context_id' => $book->contextId,
                'submission_id' => $book->submissionId,
                'publication_id' => $book->publicationId,
                'isbn13' => $book->isbn13,
                'isbn10' => $book->isbn10,
                'discovery_status' => 'not_checked',
                'sync_status' => 'pending',
                'feed_eligible' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $record = $this->findByIsbn($book->contextId, $book->isbn13);
        }

        if (!$record) {
            throw new RuntimeException('Unable to create or retrieve the Google Books state record.');
        }
        if ((int) $record->submission_id !== $book->submissionId) {
            throw new RuntimeException('The normalized ISBN is already assigned to another OMP submission in this press.');
        }

        if (
            (int) ($record->publication_id ?? 0) !== $book->publicationId ||
            (string) ($record->isbn10 ?? '') !== (string) ($book->isbn10 ?? '')
        ) {
            DB::table('google_books_records')->where('record_id', $record->record_id)->update([
                'publication_id' => $book->publicationId,
                'isbn10' => $book->isbn10,
                'updated_at' => $this->now(),
            ]);
            $record = $this->get($book->contextId, $book->submissionId, $book->isbn13);
        }

        if (!$record) {
            throw new RuntimeException('Unable to reload the Google Books state record.');
        }
        return $record;
    }

    public function upsertDiscovery(BookMetadata $book, GoogleBooksMatch $match): object
    {
        $now = $this->now();
        $existing = $this->ensureRecord($book);

        $data = [
            'publication_id' => $book->publicationId,
            'isbn10' => $book->isbn10,
            'discovery_status' => $match->ambiguous ? 'multiple_matches' : ($match->found ? 'linked' : 'not_found'),
            'last_discovered_at' => $now,
            'last_verified_at' => $match->found ? $now : ($existing->last_verified_at ?? null),
            'discovery_error' => null,
            'updated_at' => $now,
        ];

        // Never erase a previously linked Google ID because of a temporary
        // not-found/ambiguous response. Replace Google-side identifiers only
        // when the current query produced one exact ISBN match.
        if ($match->found) {
            $data += [
                'google_volume_id' => $match->volumeId,
                'google_self_link' => $match->selfLink,
                'google_info_link' => $match->infoLink,
                'google_preview_link' => $match->previewLink,
                'google_buy_link' => $match->buyLink,
                'google_saleability' => $match->saleability,
                'google_is_ebook' => $match->isEbook,
            ];
        }

        DB::table('google_books_records')->where('record_id', $existing->record_id)->update($data);
        $record = $this->get($book->contextId, $book->submissionId, $book->isbn13);
        if (!$record) {
            throw new RuntimeException('Unable to reload the Google Books discovery record.');
        }
        return $record;
    }

    public function markDiscoveryError(BookMetadata $book, string $message): void
    {
        $record = $this->ensureRecord($book);
        $now = $this->now();
        DB::table('google_books_records')->where('record_id', $record->record_id)->update([
            // Preserve a successful public link across temporary API failures.
            'discovery_status' => !empty($record->google_volume_id) ? 'linked' : 'error',
            'discovery_error' => $this->truncate($message),
            'last_discovered_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mark the current OMP representation as available to Google's crawler.
     *
     * @return bool True when feed-visible state changed.
     */
    public function markPrepared(BookMetadata $book, bool $force = false): bool
    {
        $record = $this->ensureRecord($book);
        $metadataHash = $book->metadataFingerprint();
        $contentHash = $book->contentFingerprint();
        $changed = $force ||
            (string) ($record->sync_status ?? '') !== 'feed_available' ||
            !(bool) ($record->feed_eligible ?? false) ||
            (int) ($record->publication_id ?? 0) !== $book->publicationId ||
            (string) ($record->metadata_hash ?? '') !== $metadataHash ||
            (string) ($record->content_hash ?? '') !== $contentHash;

        $now = $this->now();
        if ($changed) {
            $modifiedAt = $this->nextTimestamp($record->feed_modified_at ?? null);
            DB::table('google_books_records')->where('record_id', $record->record_id)->update([
                'publication_id' => $book->publicationId,
                'isbn10' => $book->isbn10,
                'metadata_hash' => $metadataHash,
                'content_hash' => $contentHash,
                'feed_eligible' => true,
                'sync_status' => 'feed_available',
                'feed_modified_at' => $modifiedAt,
                'last_feed_checked_at' => $now,
                'feed_error' => null,
                'last_error' => null,
                'updated_at' => $modifiedAt,
            ]);
        } else {
            DB::table('google_books_records')->where('record_id', $record->record_id)->update([
                'feed_eligible' => true,
                'last_feed_checked_at' => $now,
                'feed_error' => null,
                'last_error' => null,
                'updated_at' => $now,
            ]);
        }

        return $changed;
    }

    /** @return bool True if an existing feed-visible product was removed. */
    public function markFeedIneligible(BookMetadata $book, string $message): bool
    {
        $record = $this->ensureRecord($book);
        $feedChanged = (string) ($record->sync_status ?? '') === 'feed_available';
        $now = $this->now();
        $message = $this->truncate($message);
        DB::table('google_books_records')->where('record_id', $record->record_id)->update([
            'feed_eligible' => false,
            'sync_status' => 'ineligible',
            'feed_error' => $message,
            'last_error' => $message,
            'last_feed_checked_at' => $now,
            'updated_at' => $now,
        ]);
        return $feedChanged;
    }

    /** @return bool True if an existing feed-visible product was removed. */
    public function markFeedError(BookMetadata $book, string $message): bool
    {
        $record = $this->ensureRecord($book);
        $feedChanged = (string) ($record->sync_status ?? '') === 'feed_available';
        $now = $this->now();
        $message = $this->truncate($message);
        DB::table('google_books_records')->where('record_id', $record->record_id)->update([
            'feed_eligible' => false,
            'sync_status' => 'error',
            'feed_error' => $message,
            'last_error' => $message,
            'last_feed_checked_at' => $now,
            'updated_at' => $now,
        ]);
        return $feedChanged;
    }

    /**
     * Backwards-compatible wrapper used by older queued 0.1.0.x jobs.
     */
    public function markError(int $contextId, int $submissionId, ?string $isbn13, string $message): bool
    {
        $query = DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId);
        if ($isbn13) {
            $query->where('isbn13', $isbn13);
        }
        $records = $query->get();
        if ($records->isEmpty()) {
            return false;
        }

        $feedChanged = false;
        $now = $this->now();
        $message = $this->truncate($message);
        foreach ($records as $record) {
            $feedChanged = $feedChanged || (string) ($record->sync_status ?? '') === 'feed_available';
            DB::table('google_books_records')->where('record_id', $record->record_id)->update([
                'feed_eligible' => false,
                'sync_status' => 'error',
                'feed_error' => $message,
                'last_error' => $message,
                'last_feed_checked_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return $feedChanged;
    }

    /** @param string[] $currentIsbns */
    public function retireMissingProducts(int $contextId, int $submissionId, array $currentIsbns): int
    {
        $query = DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('sync_status', '!=', 'retired');

        $currentIsbns = array_values(array_unique(array_filter(array_map('strval', $currentIsbns))));
        if ($currentIsbns !== []) {
            $query->whereNotIn('isbn13', $currentIsbns);
        }
        return $this->retireQuery($query);
    }

    public function retireSubmission(int $contextId, int $submissionId): int
    {
        return $this->retireMissingProducts($contextId, $submissionId, []);
    }

    /** @param int[] $publishedSubmissionIds */
    public function retireMissingSubmissions(int $contextId, array $publishedSubmissionIds): int
    {
        $query = DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('sync_status', '!=', 'retired');

        $publishedSubmissionIds = array_values(array_unique(array_map('intval', $publishedSubmissionIds)));
        if ($publishedSubmissionIds !== []) {
            $query->whereNotIn('submission_id', $publishedSubmissionIds);
        }
        return $this->retireQuery($query);
    }

    public function findByIsbn(int $contextId, string $isbn13): ?object
    {
        return DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('isbn13', $isbn13)
            ->first();
    }

    public function get(int $contextId, int $submissionId, string $isbn13): ?object
    {
        return DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('isbn13', $isbn13)
            ->first();
    }

    public function getBySubmission(int $contextId, int $submissionId): Collection
    {
        return DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->orderBy('isbn13')
            ->get();
    }

    public function listByContext(int $contextId): Collection
    {
        return DB::table('google_books_records')
            ->where('context_id', $contextId)
            ->orderByDesc('updated_at')
            ->get();
    }

    /** @return array<string,int> */
    public function stats(int $contextId): array
    {
        $base = DB::table('google_books_records')->where('context_id', $contextId);
        $active = (clone $base)->where('sync_status', '!=', 'retired');
        return [
            'records' => (clone $active)->count(),
            'linked' => (clone $active)->where('discovery_status', 'linked')->whereNotNull('google_volume_id')->count(),
            'notFound' => (clone $active)->where('discovery_status', 'not_found')->count(),
            'notChecked' => (clone $active)->where('discovery_status', 'not_checked')->count(),
            'discoveryErrors' => (clone $active)->whereNotNull('discovery_error')->count(),
            'feedAvailable' => (clone $active)->where('sync_status', 'feed_available')->count(),
            'feedIneligible' => (clone $active)->where('sync_status', 'ineligible')->count(),
            'feedErrors' => (clone $active)->where('sync_status', 'error')->count(),
            'retired' => (clone $base)->where('sync_status', 'retired')->count(),
        ];
    }

    public function createRun(int $contextId, string $mode, ?int $userId): int
    {
        return (int) DB::table('google_books_sync_runs')->insertGetId([
            'context_id' => $contextId,
            'user_id' => $userId,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => $this->now(),
        ], 'run_id');
    }

    /** @param array<string,int> $counters */
    public function finishRun(int $runId, string $status, array $counters, ?string $details = null): void
    {
        DB::table('google_books_sync_runs')->where('run_id', $runId)->update([
            'status' => $status,
            'books_scanned' => $counters['scanned'] ?? 0,
            'books_linked' => $counters['linked'] ?? 0,
            'books_not_found' => $counters['notFound'] ?? 0,
            'books_updated' => $counters['updated'] ?? 0,
            'books_unchanged' => $counters['unchanged'] ?? 0,
            'books_retired' => $counters['retired'] ?? 0,
            'books_failed' => $counters['failed'] ?? 0,
            'books_skipped' => $counters['skipped'] ?? 0,
            'books_feed_ineligible' => $counters['feedIneligible'] ?? 0,
            'details' => $details,
            'completed_at' => $this->now(),
        ]);
    }

    /**
     * Accumulate one discovery batch into a long-running catalog run.
     * Sequential batch jobs keep large OMP catalogues below request/job timeouts.
     *
     * @param array<string,int> $counters
     * @param string[] $errors
     */
    public function appendRunBatch(int $runId, array $counters, array $errors, bool $final): void
    {
        DB::transaction(function () use ($runId, $counters, $errors, $final): void {
            $run = DB::table('google_books_sync_runs')->where('run_id', $runId)->lockForUpdate()->first();
            if (!$run) {
                throw new RuntimeException('Google Books catalog run not found.');
            }

            $details = trim((string) ($run->details ?? ''));
            if ($errors !== []) {
                $new = implode("\n", array_map([$this, 'truncateShort'], $errors));
                $details = trim($details . ($details !== '' ? "\n" : '') . $new);
                $details = $this->truncate($details);
            }

            $data = [
                'books_scanned' => (int) $run->books_scanned + ($counters['scanned'] ?? 0),
                'books_linked' => (int) $run->books_linked + ($counters['linked'] ?? 0),
                'books_not_found' => (int) $run->books_not_found + ($counters['notFound'] ?? 0),
                'books_updated' => (int) $run->books_updated + ($counters['updated'] ?? 0),
                'books_unchanged' => (int) $run->books_unchanged + ($counters['unchanged'] ?? 0),
                'books_retired' => (int) $run->books_retired + ($counters['retired'] ?? 0),
                'books_failed' => (int) $run->books_failed + ($counters['failed'] ?? 0),
                'books_skipped' => (int) ($run->books_skipped ?? 0) + ($counters['skipped'] ?? 0),
                'books_feed_ineligible' => (int) ($run->books_feed_ineligible ?? 0) + ($counters['feedIneligible'] ?? 0),
                'details' => $details !== '' ? $details : null,
            ];
            if ($final) {
                $data['status'] = $data['books_failed'] > 0 ? 'completed_with_errors' : 'completed';
                $data['completed_at'] = $this->now();
            }
            DB::table('google_books_sync_runs')->where('run_id', $runId)->update($data);
        });
    }

    public function latestRun(int $contextId): ?object
    {
        return DB::table('google_books_sync_runs')
            ->where('context_id', $contextId)
            ->orderByDesc('run_id')
            ->first();
    }

    /** @param string[] $modes */
    public function latestRunByModes(int $contextId, array $modes): ?object
    {
        $query = DB::table('google_books_sync_runs')
            ->where('context_id', $contextId);
        $modes = array_values(array_unique(array_filter(array_map('strval', $modes))));
        if ($modes !== []) {
            $query->whereIn('mode', $modes);
        }
        return $query->orderByDesc('run_id')->first();
    }

    private function retireQuery(object $query): int
    {
        $records = $query->get(['record_id']);
        if ($records->isEmpty()) {
            return 0;
        }
        $now = $this->now();
        $ids = $records->pluck('record_id')->map(static fn ($id): int => (int) $id)->all();
        DB::table('google_books_records')->whereIn('record_id', $ids)->update([
            'feed_eligible' => false,
            'sync_status' => 'retired',
            'feed_error' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]);
        return count($ids);
    }

    private function nextTimestamp(?string $previous): string
    {
        $previousEpoch = $previous ? (int) strtotime($previous) : 0;
        return gmdate('Y-m-d H:i:s', max(time(), $previousEpoch + 1));
    }

    private function truncate(string $message): string
    {
        return function_exists('mb_substr')
            ? mb_substr($message, 0, 65000, 'UTF-8')
            : substr($message, 0, 65000);
    }

    private function truncateShort(string $message): string
    {
        return function_exists('mb_substr')
            ? mb_substr($message, 0, 1500, 'UTF-8')
            : substr($message, 0, 1500);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
