<?php

declare(strict_types=1);

/**
 * In-memory state repository integration test.
 *
 * It exercises the repository through a small Laravel DB facade-compatible
 * implementation, without requiring a live OMP database server.
 */

namespace Illuminate\Support {
    final class Collection implements \IteratorAggregate, \Countable
    {
        /** @param array<int,mixed> $items */
        public function __construct(private array $items = [])
        {
        }

        public function isEmpty(): bool
        {
            return $this->items === [];
        }

        public function pluck(string $key): self
        {
            return new self(array_map(
                static fn (mixed $item): mixed => is_object($item) ? ($item->{$key} ?? null) : ($item[$key] ?? null),
                $this->items,
            ));
        }

        public function map(callable $callback): self
        {
            return new self(array_map($callback, $this->items));
        }

        /** @return array<int,mixed> */
        public function all(): array
        {
            return $this->items;
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function getIterator(): \Traversable
        {
            return new \ArrayIterator($this->items);
        }
    }
}

namespace GoogleBooksRepositoryTest {
    use Illuminate\Support\Collection;

    final class Store
    {
        /** @var array<string,array<int,array<string,mixed>>> */
        public static array $tables = [];
        /** @var array<string,int> */
        public static array $nextIds = [];

        public static function reset(): void
        {
            self::$tables = [
                'google_books_records' => [],
                'google_books_sync_runs' => [],
                'google_books_delivery_files' => [],
            ];
            self::$nextIds = [
                'google_books_records' => 1,
                'google_books_sync_runs' => 1,
                'google_books_delivery_files' => 1,
            ];
        }
    }

    final class QueryBuilder
    {
        /** @var array<int,callable(array<string,mixed>):bool> */
        private array $conditions = [];
        /** @var array<int,array{0:string,1:string}> */
        private array $orders = [];

        public function __construct(private string $table)
        {
            Store::$tables[$table] ??= [];
            Store::$nextIds[$table] ??= 1;
        }

        public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
        {
            if (func_num_args() === 2) {
                $operator = '=';
                $value = $operatorOrValue;
            } else {
                $operator = (string) $operatorOrValue;
            }

            $this->conditions[] = static function (array $row) use ($column, $operator, $value): bool {
                $actual = $row[$column] ?? null;
                return match ($operator) {
                    '=', '==' => $actual == $value,
                    '===' => $actual === $value,
                    '!=', '<>' => $actual != $value,
                    default => throw new \RuntimeException('Unsupported fake DB operator: ' . $operator),
                };
            };
            return $this;
        }

        /** @param array<int,mixed> $values */
        public function whereNotIn(string $column, array $values): self
        {
            $this->conditions[] = static fn (array $row): bool => !in_array($row[$column] ?? null, $values, true);
            return $this;
        }

        /** @param array<int,mixed> $values */
        public function whereIn(string $column, array $values): self
        {
            $this->conditions[] = static fn (array $row): bool => in_array($row[$column] ?? null, $values, true);
            return $this;
        }

        public function whereNotNull(string $column): self
        {
            $this->conditions[] = static fn (array $row): bool => array_key_exists($column, $row) && $row[$column] !== null;
            return $this;
        }

        public function orderBy(string $column, string $direction = 'asc'): self
        {
            $this->orders[] = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];
            return $this;
        }

        public function orderByDesc(string $column): self
        {
            return $this->orderBy($column, 'desc');
        }

        public function lockForUpdate(): self
        {
            return $this;
        }

        /** @param array<string,mixed> $data */
        public function insertOrIgnore(array $data): int
        {
            if ($this->table === 'google_books_records') {
                foreach (Store::$tables[$this->table] as $row) {
                    if (
                        (int) ($row['context_id'] ?? 0) === (int) ($data['context_id'] ?? 0)
                        && (string) ($row['isbn13'] ?? '') === (string) ($data['isbn13'] ?? '')
                    ) {
                        return 0;
                    }
                }
                $data['record_id'] ??= Store::$nextIds[$this->table]++;
            }
            Store::$tables[$this->table][] = $data;
            return 1;
        }

        /** @param array<string,mixed> $data */
        public function insert(array $data): bool
        {
            if ($this->table === 'google_books_delivery_files') {
                $data['delivery_file_id'] ??= Store::$nextIds[$this->table]++;
            }
            Store::$tables[$this->table][] = $data;
            return true;
        }

        /** @param array<string,mixed> $data */
        public function insertGetId(array $data, string $idColumn = 'id'): int
        {
            $id = Store::$nextIds[$this->table]++;
            if ($this->table === 'google_books_sync_runs') {
                $data = array_replace([
                    'books_scanned' => 0,
                    'books_linked' => 0,
                    'books_not_found' => 0,
                    'books_updated' => 0,
                    'books_unchanged' => 0,
                    'books_retired' => 0,
                    'books_failed' => 0,
                    'books_skipped' => 0,
                    'books_feed_ineligible' => 0,
                    'details' => null,
                    'completed_at' => null,
                ], $data);
            }
            $data[$idColumn] = $id;
            Store::$tables[$this->table][] = $data;
            return $id;
        }

        /** @param array<string,mixed> $data */
        public function update(array $data): int
        {
            $updated = 0;
            foreach (Store::$tables[$this->table] as &$row) {
                if (!$this->matches($row)) {
                    continue;
                }
                $row = array_replace($row, $data);
                $updated++;
            }
            unset($row);
            return $updated;
        }

        public function delete(): int
        {
            $kept = [];
            $deleted = 0;
            foreach (Store::$tables[$this->table] as $row) {
                if ($this->matches($row)) {
                    $deleted++;
                } else {
                    $kept[] = $row;
                }
            }
            Store::$tables[$this->table] = $kept;
            return $deleted;
        }

        /** @param array<int,string> $columns */
        public function get(array $columns = ['*']): Collection
        {
            $rows = array_values(array_filter(Store::$tables[$this->table], fn (array $row): bool => $this->matches($row)));
            if ($this->orders !== []) {
                usort($rows, function (array $a, array $b): int {
                    foreach ($this->orders as [$column, $direction]) {
                        $comparison = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                        if ($comparison !== 0) {
                            return $direction === 'desc' ? -$comparison : $comparison;
                        }
                    }
                    return 0;
                });
            }
            if ($columns !== ['*']) {
                $rows = array_map(
                    static fn (array $row): array => array_intersect_key($row, array_flip($columns)),
                    $rows,
                );
            }
            return new Collection(array_map(static fn (array $row): object => (object) $row, $rows));
        }

        public function first(): ?object
        {
            $items = $this->get()->all();
            return $items[0] ?? null;
        }

        public function count(): int
        {
            return $this->get()->count();
        }

        /** @param array<string,mixed> $row */
        private function matches(array $row): bool
        {
            foreach ($this->conditions as $condition) {
                if (!$condition($row)) {
                    return false;
                }
            }
            return true;
        }
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static function table(string $table): \GoogleBooksRepositoryTest\QueryBuilder
        {
            return new \GoogleBooksRepositoryTest\QueryBuilder($table);
        }

        public static function transaction(callable $callback): mixed
        {
            return $callback();
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use APP\plugins\generic\googleBooks\classes\Discovery\GoogleBooksMatch;
    use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
    use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksDeliveryRepository;
    use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
    use GoogleBooksRepositoryTest\Store;

    Store::reset();

    $assertions = 0;
    $failures = [];
    $check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
        $assertions++;
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $makeBook = static function (
        int $submissionId = 10,
        int $publicationId = 100,
        string $isbn13 = '9780306406157',
        string $title = 'Repository Test Book',
    ): BookMetadata {
        return new BookMetadata(
            contextId: 1,
            submissionId: $submissionId,
            publicationId: $publicationId,
            isbn13: $isbn13,
            isbn10: $isbn13 === '9780306406157' ? '0306406152' : null,
            title: $title,
            subtitle: null,
            contributors: [['role' => 'A01', 'roles' => ['A01'], 'name' => 'Test Author', 'orcid' => null]],
            publisher: 'Test Publisher',
            imprint: null,
            language: 'eng',
            publicationDate: '20260813',
            description: 'Repository state test.',
            licenseUrl: 'https://creativecommons.org/licenses/by/4.0/',
            freeOfCharge: true,
            prices: [],
            assets: [[
                'kind' => 'pdf',
                'fileId' => 501,
                'formatId' => 21,
                'path' => 'presses/1/monographs/10/submission/proof/book.pdf',
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size' => 1234,
                'modified' => 1720000000,
                'filename' => $isbn13 . '.pdf',
                'directSalesPrice' => 0.0,
            ]],
            seriesTitle: null,
            seriesIssn: null,
            seriesIdentifier: null,
            salesRights: [[
                'type' => '01',
                'countriesIncluded' => [],
                'regionsIncluded' => ['WORLD'],
                'countriesExcluded' => [],
                'regionsExcluded' => [],
            ]],
            markets: [],
        );
    };

    $repository = new GoogleBooksStateRepository();
    $book = $makeBook();

    $record = $repository->ensureRecord($book);
    $check((int) $record->record_id === 1, 'Initial state record was not inserted');
    $check($repository->stats(1)['records'] === 1, 'Initial state record count is wrong');

    $sameRecord = $repository->ensureRecord($book);
    $check((int) $sameRecord->record_id === 1 && $repository->stats(1)['records'] === 1, 'Idempotent ensureRecord created a duplicate');

    $check($repository->markPrepared($book) === true, 'First feed preparation was not marked as changed');
    $prepared = $repository->get(1, 10, $book->isbn13);
    $check($prepared && $prepared->sync_status === 'feed_available', 'Prepared product did not enter feed_available state');
    $check((string) $prepared->metadata_hash === $book->metadataFingerprint(), 'Metadata fingerprint was not persisted');
    $check((string) $prepared->content_hash === $book->contentFingerprint(), 'Content fingerprint was not persisted');
    $firstModified = (string) $prepared->feed_modified_at;

    $check($repository->markPrepared($book) === false, 'Unchanged product was incorrectly republished');
    $check($repository->markPrepared($book, true) === true, 'Forced refresh did not republish the product');
    $forced = $repository->get(1, 10, $book->isbn13);
    $check(strtotime((string) $forced->feed_modified_at) > strtotime($firstModified), 'Forced feed timestamp did not advance monotonically');

    $check($repository->markError(1, 10, $book->isbn13, 'test error') === true, 'Error transition did not report a feed-visible change');
    $errored = $repository->get(1, 10, $book->isbn13);
    $check($errored && $errored->sync_status === 'error' && $errored->last_error === 'test error', 'Error state was not persisted');
    $check($repository->markPrepared($book) === true, 'Valid product did not recover from error state');
    $recovered = $repository->get(1, 10, $book->isbn13);
    $check($recovered && $recovered->sync_status === 'feed_available' && $recovered->last_error === null, 'Recovered product still contains an error');

    $exact = new GoogleBooksMatch(
        true,
        'volume-123',
        'https://www.googleapis.com/books/v1/volumes/volume-123',
        'https://books.google.test/info',
        'https://books.google.test/preview',
        [$book->isbn13],
        $book->title,
        $book->publisher,
        false,
        1,
    );
    $linked = $repository->upsertDiscovery($book, $exact);
    $check($linked->discovery_status === 'linked' && $linked->google_volume_id === 'volume-123', 'Exact Google match was not linked');
    $check($repository->stats(1)['linked'] === 1, 'Exact linked statistics are wrong');

    $notFound = $repository->upsertDiscovery($book, new GoogleBooksMatch(false));
    $check($notFound->discovery_status === 'not_found', 'Not-found discovery status was not persisted');
    $check($notFound->google_volume_id === 'volume-123', 'Temporary not-found result erased a prior Google Volume ID');
    $check($repository->stats(1)['linked'] === 0, 'Not-found product was incorrectly counted as a current exact link');

    $ambiguous = $repository->upsertDiscovery($book, new GoogleBooksMatch(false, ambiguous: true, candidateCount: 2));
    $check($ambiguous->discovery_status === 'multiple_matches', 'Ambiguous discovery status was not persisted');
    $check($ambiguous->google_volume_id === 'volume-123', 'Ambiguous lookup erased the prior Google Volume ID audit trail');

    $collisionThrown = false;
    try {
        $repository->ensureRecord($makeBook(11, 110, $book->isbn13, 'Conflicting Book'));
    } catch (RuntimeException $e) {
        $collisionThrown = str_contains($e->getMessage(), 'another OMP submission');
    }
    $check($collisionThrown, 'Canonical ISBN collision across OMP submissions was not blocked');

    $check($repository->retireMissingProducts(1, 10, []) === 1, 'Missing product was not retired');
    $retired = $repository->get(1, 10, $book->isbn13);
    $check($retired && $retired->sync_status === 'retired' && $retired->google_volume_id === 'volume-123', 'Retirement did not preserve the Google identity audit trail');
    $check($repository->stats(1)['retired'] === 1, 'Retired product statistics are wrong');

    $check($repository->markPrepared($book) === true, 'Retired product was not reactivated when valid again');
    $reactivated = $repository->get(1, 10, $book->isbn13);
    $check($reactivated && $reactivated->sync_status === 'feed_available', 'Reactivated product is not feed-available');

    $newPublicationBook = $makeBook(10, 101, $book->isbn13, 'Repository Test Book - corrected');
    $updatedRecord = $repository->ensureRecord($newPublicationBook);
    $check((int) $updatedRecord->publication_id === 101, 'Current OMP publication version was not updated');
    $check($repository->markPrepared($newPublicationBook) === true, 'New OMP publication version was mistaken for an unchanged product');

    $secondBook = $makeBook(20, 200, '9780131103627', 'Second Repository Test Book');
    $repository->ensureRecord($secondBook);
    $repository->markPrepared($secondBook);
    $check($repository->retireMissingSubmissions(1, [10]) === 1, 'Unpublished submission reconciliation did not retire the orphaned product');
    $check($repository->get(1, 20, $secondBook->isbn13)?->sync_status === 'retired', 'Orphaned product remains active');

    $runId = $repository->createRun(1, 'force', 77);
    $repository->finishRun($runId, 'completed_with_warnings', [
        'scanned' => 2,
        'linked' => 1,
        'notFound' => 1,
        'updated' => 2,
        'unchanged' => 0,
        'retired' => 1,
        'failed' => 0,
    ], 'One non-fatal warning');
    $latestRun = $repository->latestRun(1);
    $check($latestRun && (int) $latestRun->run_id === $runId, 'Latest synchronization run could not be retrieved');
    $check($latestRun && $latestRun->status === 'completed_with_warnings', 'Synchronization run status was not persisted');
    $check($latestRun && (int) $latestRun->books_retired === 1, 'Synchronization retired counter was not persisted');


    $batchRunId = $repository->createRun(1, 'discovery', 77);
    $repository->appendRunBatch($batchRunId, [
        'scanned' => 25,
        'linked' => 8,
        'notFound' => 14,
        'failed' => 1,
        'skipped' => 2,
        'feedIneligible' => 0,
    ], ['Submission 7: transient API error'], false);
    $batchInProgress = $repository->latestRun(1);
    $check($batchInProgress && $batchInProgress->status === 'running' && (int) $batchInProgress->books_scanned === 25, 'First discovery batch was not accumulated without prematurely completing the run');
    $repository->appendRunBatch($batchRunId, [
        'scanned' => 6,
        'linked' => 3,
        'notFound' => 2,
        'failed' => 0,
        'skipped' => 1,
        'feedIneligible' => 0,
    ], [], true);
    $batchComplete = $repository->latestRun(1);
    $check($batchComplete && (int) $batchComplete->books_scanned === 31 && (int) $batchComplete->books_linked === 11 && (int) $batchComplete->books_not_found === 16 && (int) $batchComplete->books_skipped === 3, 'Sequential discovery batches did not accumulate counters correctly');
    $check($batchComplete && $batchComplete->status === 'completed_with_errors' && str_contains((string) $batchComplete->details, 'transient API error'), 'Final discovery batch did not preserve errors and finalize the run status');

    $deliveryRepository = new GoogleBooksDeliveryRepository();
    $deliveryRepository->markSuccess(1, 'google_sftp:abc', 'onix/AB12C34-full/feed.xml', 'fingerprint-1', 123);
    $delivered = $deliveryRepository->get(1, 'google_sftp:abc', 'onix/AB12C34-full/feed.xml');
    $check($delivered && $delivered->status === 'delivered' && (int) $delivered->file_size === 123, 'Delivery repository did not persist a successful file state');
    $deliveryRepository->markSuccess(1, 'google_sftp:abc', 'onix/AB12C34-full/feed.xml', 'fingerprint-2', 456);
    $updatedDelivery = $deliveryRepository->get(1, 'google_sftp:abc', 'onix/AB12C34-full/feed.xml');
    $check($updatedDelivery && $updatedDelivery->fingerprint === 'fingerprint-2' && (int) $updatedDelivery->file_size === 456, 'Delivery repository did not update an existing path state');
    $deliveryRepository->markError(1, 'google_sftp:abc', 'ebooks/AB12C34/9780306406157.pdf', 'fingerprint-pdf', 999, 'simulated transport failure');
    $states = $deliveryRepository->listByTransport(1, 'google_sftp:abc');
    $check(count($states) === 2, 'Delivery repository transport listing is incomplete');
    $errorState = $deliveryRepository->get(1, 'google_sftp:abc', 'ebooks/AB12C34/9780306406157.pdf');
    $check($errorState && $errorState->status === 'error' && str_contains((string) $errorState->last_error, 'simulated'), 'Delivery repository did not persist an error state');
    $deliveryRepository->forget((int) $errorState->delivery_file_id);
    $check($deliveryRepository->get(1, 'google_sftp:abc', 'ebooks/AB12C34/9780306406157.pdf') === null, 'Delivery repository did not forget a stale managed path');

    if ($failures !== []) {
        fwrite(STDERR, 'FAILED ' . count($failures) . " of {$assertions} repository assertions\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, " - {$failure}\n");
        }
        exit(1);
    }

    echo "OK {$assertions} repository assertions\n";
}
