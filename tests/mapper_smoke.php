<?php

declare(strict_types=1);

namespace MapperSmoke {
    final class State
    {
        public static mixed $fileRepository = null;
        public static mixed $sectionRepository = null;
        public static mixed $fileService = null;
        /** @var array<int,string> */
        public static array $userGroupRoles = [];
        /** @var array<int,mixed> */
        public static array $publicationFormats = [];
    }

    final class ArrayIterator
    {
        private int $offset = 0;

        /** @param array<int,mixed> $items */
        public function __construct(private array $items)
        {
        }

        public function next(): mixed
        {
            return $this->items[$this->offset++] ?? null;
        }
    }

    final class PublicationFormatDAOStub
    {
        /** @return array<int,mixed> */
        public function getByPublicationId(int $publicationId, ?int $contextId = null): array
        {
            return State::$publicationFormats;
        }
    }

    final class FileSystem
    {
        /** @param array<string,array{size:int,modified:int}> $files */
        public function __construct(private array $files)
        {
        }

        public function fileExists(string $path): bool
        {
            return isset($this->files[$path]);
        }

        public function fileSize(string $path): int
        {
            return $this->files[$path]['size'];
        }

        public function lastModified(string $path): int
        {
            return $this->files[$path]['modified'];
        }
    }

    final class FileService
    {
        public function __construct(public FileSystem $fs)
        {
        }
    }

    final class Container
    {
        public function get(string $service): mixed
        {
            if ($service !== 'file') {
                throw new \RuntimeException('Unexpected service: ' . $service);
            }
            return State::$fileService;
        }
    }

    final class SubmissionFileCollector
    {
        private array $submissionIds = [];
        private array $fileStages = [];
        private ?int $assocType = null;
        private array $assocIds = [];

        /** @param array<int,mixed> $files */
        public function __construct(private array $files)
        {
        }

        public function filterBySubmissionIds(array $ids): self
        {
            $this->submissionIds = $ids;
            return $this;
        }

        public function filterByFileStages(array $stages): self
        {
            $this->fileStages = $stages;
            return $this;
        }

        public function filterByAssoc(?int $type, ?array $ids = null): self
        {
            $this->assocType = $type;
            $this->assocIds = $ids ?? [];
            return $this;
        }

        /** @return array<int,mixed> */
        public function getMany(): array
        {
            return array_values(array_filter($this->files, function (object $file): bool {
                if ($this->submissionIds !== [] && !in_array((int) $file->getData('submissionId'), $this->submissionIds, true)) {
                    return false;
                }
                if ($this->fileStages !== [] && !in_array((int) $file->getData('fileStage'), $this->fileStages, true)) {
                    return false;
                }
                if ($this->assocType !== null && (int) $file->getData('assocType') !== $this->assocType) {
                    return false;
                }
                if ($this->assocIds !== [] && !in_array((int) $file->getData('assocId'), $this->assocIds, true)) {
                    return false;
                }
                return true;
            }));
        }
    }

    final class SubmissionFileRepository
    {
        /** @param array<int,mixed> $files */
        public function __construct(private array $files)
        {
        }

        public function getCollector(): SubmissionFileCollector
        {
            return new SubmissionFileCollector($this->files);
        }
    }

    final class SectionRepository
    {
        /** @param array<int,mixed> $sections */
        public function __construct(private array $sections)
        {
        }

        public function get(int $id): mixed
        {
            return $this->sections[$id] ?? null;
        }
    }

    final class Context
    {
        public function getId(): int
        {
            return 9;
        }

        public function getPrimaryLocale(): string
        {
            return 'en_US';
        }

        public function getData(string $key): mixed
        {
            return match ($key) {
                'publisher' => 'Example University Press',
                default => null,
            };
        }

        public function getName(?string $locale = null): string
        {
            return 'Example Press';
        }
    }

    final class Author
    {
        public function getUserGroupId(): int
        {
            return 7;
        }

        public function getFullName(): string
        {
            return 'Editor Example';
        }

        public function getOrcid(): string
        {
            return 'https://orcid.org/0000-0002-1825-0097';
        }

        public function hasVerifiedOrcid(): bool
        {
            return true;
        }
    }

    final class IdentificationCode
    {
        public function __construct(private string $code, private string $value)
        {
        }

        public function getCode(): string
        {
            return $this->code;
        }

        public function getValue(): string
        {
            return $this->value;
        }
    }

    final class Format
    {
        /** @param array<int,IdentificationCode> $codes */
        public function __construct(
            private int $id,
            private array $codes,
            private string $imprint,
        ) {
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getIsApproved(): bool
        {
            return true;
        }

        public function getIsAvailable(): bool
        {
            return true;
        }

        public function getIdentificationCodes(): ArrayIterator
        {
            return new ArrayIterator($this->codes);
        }

        public function getImprint(): string
        {
            return $this->imprint;
        }

        public function getSalesRights(): ArrayIterator
        {
            return new ArrayIterator([]);
        }

        public function getMarkets(): ArrayIterator
        {
            return new ArrayIterator([]);
        }

        public function getProductAvailabilityCode(): string
        {
            return '20';
        }
    }

    final class Publication
    {
        /** @param array<int,Format> $formats */
        public function __construct(private array $formats)
        {
        }

        public function getId(): int
        {
            return 501;
        }

        public function getData(string $key, ?string $locale = null): mixed
        {
            $localized = [
                'title' => ['en_US' => 'Edited &amp; Tested <em>Book</em>'],
                'subtitle' => ['en_US' => '<p>A mapper integration test</p>'],
                'abstract' => ['en_US' => '<p>Metadata &amp; file mapping.</p>'],
                'coverImage' => ['en_US' => ['uploadName' => 'mapper-cover.png']],
            ];
            if (array_key_exists($key, $localized)) {
                if ($locale !== null) {
                    return $localized[$key][$locale] ?? null;
                }
                return $localized[$key];
            }
            return match ($key) {
                'locale' => 'en_US',
                'datePublished' => '2026-08-13',
                'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/',
                'seriesId' => 77,
                'authors' => [new Author()],
                'publicationFormats' => null,
                default => null,
            };
        }

        public function getLocalizedData(string $key): mixed
        {
            $value = $this->getData($key, 'en_US');
            return $value;
        }
    }

    final class Series
    {
        public function getData(string $key, ?string $locale = null): mixed
        {
            if ($key === 'title') {
                return $locale === null ? ['en_US' => 'Research Series'] : 'Research Series';
            }
            return match ($key) {
                'onlineIssn' => '2049.3630',
                'printIssn' => null,
                default => null,
            };
        }

        public function getLocalizedData(string $key): mixed
        {
            return $key === 'title' ? 'Research Series' : null;
        }

        public function getOnlineISSN(): string
        {
            return '2049.3630';
        }

        public function getPrintISSN(): ?string
        {
            return null;
        }
    }
}

namespace APP\core {
    final class Application
    {
        public const ASSOC_TYPE_PUBLICATION_FORMAT = 0x0100;
    }
}

namespace APP\facades {
    final class Repo
    {
        public static function submissionFile(): mixed
        {
            return \MapperSmoke\State::$fileRepository;
        }

        public static function section(): mixed
        {
            return \MapperSmoke\State::$sectionRepository;
        }
    }
}

namespace APP\file {
    final class PublicFileManager
    {
        public function getContextFilesPath(int $contextId): string
        {
            return __DIR__ . '/tmp_mapper';
        }
    }
}

namespace APP\submission {
    class Submission
    {
        public function __construct(private object $publication)
        {
        }

        public function getCurrentPublication(): object
        {
            return $this->publication;
        }

        public function getId(): int
        {
            return 301;
        }
    }
}

namespace PKP\db {
    final class DAORegistry
    {
        public static function getDAO(string $name): mixed
        {
            if ($name === 'PublicationFormatDAO') {
                return new \MapperSmoke\PublicationFormatDAOStub();
            }
            throw new \RuntimeException('Unexpected DAO: ' . $name);
        }
    }
}

namespace PKP\submissionFile {
    class SubmissionFile
    {
        public const SUBMISSION_FILE_PROOF = 10;

        /** @param array<string,mixed> $data */
        public function __construct(private array $data)
        {
        }

        public function getData(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }

        public function getViewable(): bool
        {
            return (bool) ($this->data['viewable'] ?? false);
        }

        public function getChapterId(): int
        {
            return (int) ($this->data['chapterId'] ?? 0);
        }

        public function getDirectSalesPrice(): mixed
        {
            return $this->data['directSalesPrice'] ?? null;
        }
    }
}

namespace PKP\userGroup {
    final class UserGroup
    {
        public static function find(int $id): ?object
        {
            $role = \MapperSmoke\State::$userGroupRoles[$id] ?? null;
            return $role ? (object) ['nameLocaleKey' => $role] : null;
        }
    }
}

namespace {
    function app(): \MapperSmoke\Container
    {
        return new \MapperSmoke\Container();
    }

    require __DIR__ . '/bootstrap.php';

    use APP\plugins\generic\googleBooks\classes\Sync\OmpBookMapper;
    use APP\submission\Submission;
    use MapperSmoke\Context;
    use MapperSmoke\FileService;
    use MapperSmoke\FileSystem;
    use MapperSmoke\Format;
    use MapperSmoke\IdentificationCode;
    use MapperSmoke\Publication;
    use MapperSmoke\SectionRepository;
    use MapperSmoke\Series;
    use MapperSmoke\State;
    use MapperSmoke\SubmissionFileRepository;
    use PKP\submissionFile\SubmissionFile;

    $tests = 0;
    $failures = [];

    function mapperCheck(bool $condition, string $message): void
    {
        global $tests, $failures;
        $tests++;
        if (!$condition) {
            $failures[] = $message;
        }
    }

    $tmpDir = __DIR__ . '/tmp_mapper';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0770, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Unable to create mapper test directory.');
    }
    file_put_contents($tmpDir . '/mapper-cover.png', "PNG\r\nmapper-test");

    $pdfPath = 'mapper/book.pdf';
    $epubPath = 'mapper/book.epub';
    $chapterPath = 'mapper/chapter.pdf';
    $hiddenPath = 'mapper/hidden.pdf';
    $unavailablePath = 'mapper/unavailable.epub';

    State::$fileService = new FileService(new FileSystem([
        $pdfPath => ['size' => 1000, 'modified' => 1700000100],
        $epubPath => ['size' => 2000, 'modified' => 1700000200],
        $chapterPath => ['size' => 300, 'modified' => 1700000300],
        $hiddenPath => ['size' => 400, 'modified' => 1700000400],
        $unavailablePath => ['size' => 500, 'modified' => 1700000500],
    ]));
    State::$sectionRepository = new SectionRepository([77 => new Series()]);
    State::$userGroupRoles = [7 => 'default.groups.name.volumeEditor'];

    $formatPdf = new Format(11, [new IdentificationCode('15', '978-0-306-40615-7')], 'Example Academic');
    $formatEpub = new Format(12, [new IdentificationCode('15', '978.0.306.40615.7')], 'Example Academic');
    State::$publicationFormats = [$formatPdf, $formatEpub];
    $publication = new Publication([$formatPdf, $formatEpub]);
    $submission = new Submission($publication);

    State::$fileRepository = new SubmissionFileRepository([
        new SubmissionFile([
            'submissionId' => 301,
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => \APP\core\Application::ASSOC_TYPE_PUBLICATION_FORMAT,
            'assocId' => 11,
            'fileId' => 901,
            'path' => $pdfPath,
            'mimetype' => 'application/pdf',
            'viewable' => true,
            'chapterId' => 0,
            'directSalesPrice' => 0,
        ]),
        new SubmissionFile([
            'submissionId' => 301,
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => \APP\core\Application::ASSOC_TYPE_PUBLICATION_FORMAT,
            'assocId' => 12,
            'fileId' => 902,
            'path' => $epubPath,
            'mimetype' => 'application/epub+zip',
            'viewable' => true,
            'chapterId' => 0,
            'directSalesPrice' => 0,
        ]),
        new SubmissionFile([
            'submissionId' => 301,
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => \APP\core\Application::ASSOC_TYPE_PUBLICATION_FORMAT,
            'assocId' => 11,
            'fileId' => 903,
            'path' => $chapterPath,
            'mimetype' => 'application/pdf',
            'viewable' => true,
            'chapterId' => 44,
            'directSalesPrice' => 0,
        ]),
        new SubmissionFile([
            'submissionId' => 301,
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => \APP\core\Application::ASSOC_TYPE_PUBLICATION_FORMAT,
            'assocId' => 11,
            'fileId' => 904,
            'path' => $hiddenPath,
            'mimetype' => 'application/pdf',
            'viewable' => false,
            'chapterId' => 0,
            'directSalesPrice' => 0,
        ]),
        new SubmissionFile([
            'submissionId' => 301,
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => \APP\core\Application::ASSOC_TYPE_PUBLICATION_FORMAT,
            'assocId' => 12,
            'fileId' => 905,
            'path' => $unavailablePath,
            'mimetype' => 'application/epub+zip',
            'viewable' => true,
            'chapterId' => 0,
            'directSalesPrice' => null,
        ]),
    ]);

    $books = (new OmpBookMapper())->mapSubmission($submission, new Context(), false, true);
    mapperCheck(count($books) === 1, 'equivalent punctuated ISBNs produced duplicate Google products');

    $book = $books[0] ?? null;
    mapperCheck($book !== null && $book->isbn13 === '9780306406157', 'mapper did not canonicalize ISBN-13');
    mapperCheck($book !== null && $book->isbn10 === '0306406152', 'mapper did not derive ISBN-10 equivalent');
    mapperCheck($book !== null && $book->seriesIssn === '20493630', 'mapper did not canonicalize dotted series ISSN');
    mapperCheck($book !== null && $book->seriesTitle === 'Research Series', 'mapper did not preserve the OMP series title');
    mapperCheck($book !== null && $book->seriesIdentifier === 'OMP9S77', 'mapper did not create a stable context-qualified OMP series identifier');
    mapperCheck($book !== null && $book->title === 'Edited & Tested Book', 'mapper did not decode and clean localized title metadata');
    mapperCheck($book !== null && $book->subtitle === 'A mapper integration test', 'mapper did not clean localized subtitle metadata');
    mapperCheck($book !== null && $book->description === 'Metadata & file mapping.', 'mapper did not clean localized synopsis metadata');
    mapperCheck($book !== null && $book->publicationDate === '20260813', 'mapper did not normalize publication date');
    mapperCheck($book !== null && $book->freeOfCharge === true, 'mapper did not infer open access from OMP proof pricing');
    mapperCheck($book !== null && $book->prices === [], 'open-access product retained paid prices');
    mapperCheck($book !== null && ($book->salesRights[0]['regionsIncluded'] ?? []) === ['WORLD'], 'default worldwide rights were not added for a free title');
    mapperCheck($book !== null && ($book->contributors[0]['roles'] ?? []) === ['B01'], 'edited-volume contributor did not preserve a single OMP-derived B01 role');
    mapperCheck($book !== null && ($book->contributors[0]['orcid'] ?? null) === 'https://orcid.org/0000-0002-1825-0097', 'verified ORCID was not preserved');

    $extensions = $book ? array_column($book->assets, 'extension') : [];
    sort($extensions);
    mapperCheck($extensions === ['epub', 'pdf', 'png'], 'mapper did not merge PDF, EPUB and cover assets under one canonical ISBN');
    mapperCheck($book !== null && count($book->assets) === 3, 'chapter, hidden or unavailable proof files leaked into the whole-book asset set');
    mapperCheck($book !== null && array_column($book->assets, 'filename') === [
        '9780306406157.epub',
        '9780306406157.pdf',
        '9780306406157_frontcover.png',
    ], 'canonical Google feed filenames were not generated deterministically');

    // Google's one-time ONIX validation sample is metadata-only. Confirm
    // that the mapper can still expose ISBN metadata when no public content
    // proof is available, while the normal live-feed mapping remains strict.
    State::$fileRepository = new SubmissionFileRepository([]);
    $metadataOnlyBooks = (new OmpBookMapper())->mapSubmission($submission, new Context(), false, true, false);
    mapperCheck(count($metadataOnlyBooks) === 1, 'metadata-only mapper mode dropped a valid ISBN product without proof assets');
    mapperCheck(array_values(array_filter($metadataOnlyBooks[0]->assets ?? [], static fn (array $asset): bool => ($asset['kind'] ?? '') === 'content')) === [], 'metadata-only mapper mode unexpectedly manufactured content assets');
    $strictBooksWithoutAssets = (new OmpBookMapper())->mapSubmission($submission, new Context(), false, true, true);
    mapperCheck($strictBooksWithoutAssets === [], 'live-feed mapper mode accepted a product without PDF/EPUB assets');

    // OMP 3.5 stores identification codes on PublicationFormat rows. Historical
    // catalogues can use ONIX List 5 code 24 (co-publisher ISBN-13) instead of
    // the primary code 15. Discovery must still see it, even when the
    // Publication object itself does not carry an embedded publicationFormats
    // property.
    $coPublisherFormat = new Format(24, [new IdentificationCode('24', '978-1-4028-9462-6')], 'Co-publisher');
    State::$publicationFormats = [$coPublisherFormat];
    $coPublication = new Publication([]);
    $coSubmission = new Submission($coPublication);
    $discoveryBooks = (new OmpBookMapper())->mapDiscoverySubmission($coSubmission, new Context());
    mapperCheck(count($discoveryBooks) === 1, 'discovery ignored an ISBN-13 stored under ONIX identifier type 24');
    mapperCheck(($discoveryBooks[0]->isbn13 ?? null) === '9781402894626', 'co-publisher ISBN-13 was not normalized for Google discovery');

    $coMetadataFeed = (new OmpBookMapper())->mapSubmission($coSubmission, new Context(), false, true, false);
    mapperCheck(count($coMetadataFeed) === 1 && ($coMetadataFeed[0]->isbn13 ?? null) === '9781402894626', 'feed metadata mapping did not use code 24 as a safe historical fallback');

    @unlink($tmpDir . '/mapper-cover.png');
    @rmdir($tmpDir);

    if ($failures !== []) {
        fwrite(STDERR, sprintf("FAILED %d of %d mapper assertions\n", count($failures), $tests));
        foreach ($failures as $failure) {
            fwrite(STDERR, ' - ' . $failure . "\n");
        }
        exit(1);
    }

    echo sprintf("OK %d mapper assertions\n", $tests);
}
