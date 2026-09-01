<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Feed;

use APP\facades\Repo;
use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixBuilder;
use APP\plugins\generic\googleBooks\classes\Onix\GoogleOnixValidator;
use APP\plugins\generic\googleBooks\classes\Onix\OnixEnrichmentService;
use APP\plugins\generic\googleBooks\classes\Repository\GoogleBooksStateRepository;
use APP\plugins\generic\googleBooks\classes\Sync\OmpBookMapper;
use APP\plugins\generic\googleBooks\GoogleBooksPlugin;
use APP\submission\Submission;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class FeedManifestService
{
    private const VALIDATION_TARGET_COUNT = 10;

    private OmpBookMapper $mapper;
    private GoogleOnixValidator $validator;
    private GoogleBooksStateRepository $repository;
    private OnixEnrichmentService $enrichment;

    public function __construct(private GoogleBooksPlugin $plugin)
    {
        $this->mapper = new OmpBookMapper();
        $this->validator = new GoogleOnixValidator();
        $this->repository = new GoogleBooksStateRepository();
        $this->enrichment = new OnixEnrichmentService();
    }

    /** @return BookMetadata[] */
    public function books(object $context, ?string $collectionCode = null, bool $rightsProfile = false): array
    {
        $books = [];
        $contextId = (int) $context->getId();
        $defaultFree = $this->plugin->boolSetting($contextId, 'defaultFreeOfCharge', false);
        $defaultWorldwideRights = $this->plugin->boolSetting($contextId, 'defaultWorldwideRightsForFree', false);
        $defaultBisacCode = (string) $this->plugin->getSetting($contextId, 'defaultBisacCode');
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany();

        foreach ($submissions as $submission) {
            foreach ($this->mapper->mapSubmission($submission, $context, $defaultFree, $defaultWorldwideRights) as $book) {
                $book = $this->enrichment->enrich($book, $submission, $context, $defaultBisacCode);
                $errors = $rightsProfile ? $this->validator->validateRightsBook($book) : $this->validator->validateBook($book);
                if ($errors !== []) {
                    continue;
                }
                if ($collectionCode !== null && $this->plugin->getCollectionCodeForBook($contextId, $book) !== $collectionCode) {
                    continue;
                }
                $state = $this->repository->get($book->contextId, $book->submissionId, $book->isbn13);
                if (!$state || $state->sync_status !== 'feed_available') {
                    continue;
                }
                if (isset($books[$book->isbn13]) && $books[$book->isbn13]->submissionId !== $book->submissionId) {
                    throw new RuntimeException(
                        'Duplicate normalized ISBN ' . $book->isbn13 . ' is assigned to OMP submissions ' .
                        $books[$book->isbn13]->submissionId . ' and ' . $book->submissionId . '.'
                    );
                }
                $books[$book->isbn13] = $book;
            }
        }
        ksort($books);
        return array_values($books);
    }

    public function buildOnix(object $context, string $collectionCode, bool $includeSupplyDetail): string
    {
        $books = $this->books($context, $collectionCode, $includeSupplyDetail);
        $xml = (new GoogleOnixBuilder())->build(
            $books,
            (string) ($context->getData('publisher') ?: $context->getName($context->getPrimaryLocale())),
            (string) $context->getData('contactName'),
            (string) $context->getData('contactEmail'),
            $this->sentAt((int) $context->getId()),
            $includeSupplyDetail,
        );

        return $this->assertDeliverableXml(
            $xml,
            $includeSupplyDetail ? 'rights' : 'bibliographic',
            count($books),
            $includeSupplyDetail,
        );
    }

    public function buildValidationOnix(object $context, int $submissionId): string
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId() || (int) $submission->getData('status') !== Submission::STATUS_PUBLISHED) {
            throw new RuntimeException('The selected validation monograph is not published in this press.');
        }

        $contextId = (int) $context->getId();
        $defaultFree = $this->plugin->boolSetting($contextId, 'defaultFreeOfCharge', false);
        $defaultWorldwideRights = $this->plugin->boolSetting($contextId, 'defaultWorldwideRightsForFree', false);
        $defaultBisacCode = (string) $this->plugin->getSetting($contextId, 'defaultBisacCode');
        $books = [];

        // Google Play validates the commercial structure in the ten-record ONIX
        // sample as well as its bibliographic metadata. Keep the title selected
        // by the manager as the preferred anchor, then supplement it with real
        // published OMP products that already have valid SalesRights and supply
        // terms. Content assets are not required for the sample because they are
        // validated and delivered through the separate ebook/content feed.
        // Compatibility note: validateCommercialMetadataBook() begins with the
        // same validateMetadataBook($book) checks used by the earlier sample and
        // then adds Google's commercial-profile requirements.
        $appendEligibleProducts = function (object $candidate) use (
            &$books,
            $context,
            $defaultFree,
            $defaultWorldwideRights,
            $defaultBisacCode,
        ): void {
            foreach ($this->mapper->mapSubmission(
                $candidate,
                $context,
                $defaultFree,
                $defaultWorldwideRights,
                false,
            ) as $book) {
                $book = $this->enrichment->enrich($book, $candidate, $context, $defaultBisacCode);
                if ($this->validator->validateCommercialMetadataBook($book) !== []) {
                    continue;
                }
                if (isset($books[$book->isbn13])) {
                    continue;
                }
                $books[$book->isbn13] = $book;
                if (count($books) >= self::VALIDATION_TARGET_COUNT) {
                    return;
                }
            }
        };

        $appendEligibleProducts($submission);

        if (count($books) < self::VALIDATION_TARGET_COUNT) {
            $published = Repo::submission()
                ->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany();

            foreach ($published as $candidate) {
                if ((int) $candidate->getId() === $submissionId) {
                    continue;
                }
                $appendEligibleProducts($candidate);
                if (count($books) >= self::VALIDATION_TARGET_COUNT) {
                    break;
                }
            }
        }

        if (count($books) < self::VALIDATION_TARGET_COUNT) {
            throw new RuntimeException(sprintf(
                'Google Play Books validation requires at least %d real products with valid ISBN, at least one A01 author, bibliographic metadata, SalesRights and supply terms; only %d eligible product(s) were found.',
                self::VALIDATION_TARGET_COUNT,
                count($books),
            ));
        }

        $xml = (new GoogleOnixBuilder())->build(
            array_values($books),
            (string) ($context->getData('publisher') ?: $context->getName($context->getPrimaryLocale())),
            (string) $context->getData('contactName'),
            (string) $context->getData('contactEmail'),
            $this->sentAt($contextId),
            true,
        );

        return $this->assertDeliverableXml(
            $xml,
            'validation-commercial',
            self::VALIDATION_TARGET_COUNT,
            true,
        );
    }

    /**
     * @return array<string,array{book:BookMetadata,asset:array<string,mixed>,modified:int}>
     */
    public function assets(object $context, string $collectionCode): array
    {
        $assets = [];
        foreach ($this->books($context, $collectionCode, true) as $book) {
            $record = $this->repository->get($book->contextId, $book->submissionId, $book->isbn13);
            $distributionModified = $record && $record->feed_modified_at
                ? (new DateTimeImmutable((string) $record->feed_modified_at, new DateTimeZone('UTC')))->getTimestamp()
                : 0;
            foreach ($book->assets as $asset) {
                $modified = max((int) ($asset['modified'] ?? 0), (int) $distributionModified);
                if (isset($assets[$asset['filename']])) {
                    throw new RuntimeException('Duplicate feed filename after ISBN normalization: ' . $asset['filename']);
                }
                $assets[$asset['filename']] = [
                    'book' => $book,
                    'asset' => $asset,
                    'modified' => $modified,
                ];
            }
        }
        ksort($assets);
        return $assets;
    }

    /**
     * Refuse to expose a partially generated or structurally invalid ONIX
     * document. The full XML is built in memory first, then parsed and, when
     * OMP's ONIX schema is available, XSD-validated before FeedHandler writes
     * any HTTP response bytes. Commercial profiles additionally require the
     * Google Play SalesRights/ProductSupply structure on every Product.
     */
    private function assertDeliverableXml(
        string $xml,
        string $profile,
        int $expectedProducts,
        bool $requireCommercial = false,
    ): string {
        $errors = $this->validator->validateXml($xml);
        if ($requireCommercial) {
            $errors = array_merge($errors, $this->validator->validateCommercialXml($xml));
        }

        $openProducts = substr_count($xml, '<Product>');
        $closedProducts = substr_count($xml, '</Product>');

        if ($openProducts !== $expectedProducts || $closedProducts !== $expectedProducts) {
            $errors[] = sprintf(
                'Generated %s ONIX product count mismatch: expected %d, opened %d, closed %d.',
                $profile,
                $expectedProducts,
                $openProducts,
                $closedProducts,
            );
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Google Books ' . $profile . ' ONIX failed pre-delivery validation: ' .
                implode(' | ', array_slice(array_values(array_unique($errors)), 0, 10))
            );
        }

        return $xml;
    }

    /**
     * Keep ONIX SentDateTime aligned with the feed revision. A repeated GET for
     * the same feed revision must return byte-identical XML and a matching
     * Last-Modified value. The timestamp changes only when a synchronization
     * operation explicitly bumps the feed revision.
     */
    private function sentAt(int $contextId): DateTimeImmutable
    {
        $revision = $this->plugin->getFeedRevision($contextId);
        return (new DateTimeImmutable('@' . $revision))->setTimezone(new DateTimeZone('UTC'));
    }
}
