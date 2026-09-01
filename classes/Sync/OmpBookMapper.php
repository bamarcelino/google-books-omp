<?php

declare(strict_types=1);

/**
 * Maps OMP 3.5 monograph/publication data to the neutral Google Books model.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Sync;

use APP\core\Application;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;
use APP\submission\Submission;
use DateTimeImmutable;
use Exception;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;
use Throwable;
use PKP\userGroup\UserGroup;

final class OmpBookMapper
{
    /** ONIX editor roles used by OMP contributor groups for organized volumes. */
    private const ORGANIZER_ROLES = ['B01', 'B13', 'B21'];

    /**
     * A single OMP monograph can expose more than one Google product when
     * publication formats have different eISBNs.
     *
     * @return BookMetadata[]
     */
    public function mapSubmission(
        Submission $submission,
        object $context,
        bool $defaultFreeOfCharge = false,
        bool $defaultWorldwideRightsForFree = false,
        bool $requireContentAssets = true,
    ): array {
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return [];
        }

        $common = $this->commonMetadata($submission, $publication, $context);
        $products = [];

        foreach ($this->publicationFormats($publication, $context) as $format) {
            if (method_exists($format, 'getIsApproved') && !$format->getIsApproved()) {
                continue;
            }
            if (method_exists($format, 'getIsAvailable') && !$format->getIsAvailable()) {
                continue;
            }

            $isbn13 = $this->getFormatIsbn13($format);
            if (!$isbn13) {
                continue;
            }

            $assets = $this->getFormatAssets($submission, $publication, $format, $isbn13);
            if ($requireContentAssets && $assets === []) {
                continue;
            }

            $markets = $this->getFormatMarkets($format);
            $prices = $this->pricesFromMarkets($markets);
            $directFileFree = $this->assetsAreFree($assets);
            $free = $prices === []
                ? ($directFileFree ?? $defaultFreeOfCharge)
                : $this->pricesAreFree($prices);
            if ($free) {
                $prices = [];
            }

            $salesRights = $this->getFormatSalesRights($format);
            if ($free && $salesRights === [] && $defaultWorldwideRightsForFree) {
                $salesRights[] = [
                    'type' => '02',
                    'countriesIncluded' => [],
                    'regionsIncluded' => ['WORLD'],
                    'countriesExcluded' => [],
                    'regionsExcluded' => [],
                ];
            }

            $cover = $this->getCoverAsset(
                $publication,
                (int) $context->getId(),
                $isbn13,
                (string) $common['language'],
            );
            if ($cover) {
                $assets[] = $cover;
            }

            $isbn10 = IdentifierNormalizer::isbn13To10($isbn13);
            $imprint = method_exists($format, 'getImprint') ? $this->cleanText((string) $format->getImprint()) : '';

            if (isset($products[$isbn13])) {
                $products[$isbn13]->assets = $this->uniqueAssets(array_merge($products[$isbn13]->assets, $assets));
                $products[$isbn13]->prices = $this->uniqueRows(array_merge($products[$isbn13]->prices, $prices));
                $products[$isbn13]->salesRights = $this->uniqueRows(array_merge($products[$isbn13]->salesRights, $salesRights));
                $products[$isbn13]->markets = $this->uniqueRows(array_merge($products[$isbn13]->markets, $markets));
                $products[$isbn13]->freeOfCharge = $products[$isbn13]->freeOfCharge && $free;
                continue;
            }

            $products[$isbn13] = new BookMetadata(
                (int) $context->getId(),
                (int) $submission->getId(),
                (int) $publication->getId(),
                $isbn13,
                $isbn10,
                $common['title'],
                $common['subtitle'],
                $common['contributors'],
                $common['publisher'],
                $imprint !== '' ? $imprint : null,
                $common['language'],
                $common['publicationDate'],
                $common['description'],
                $common['licenseUrl'],
                $free,
                $prices,
                $assets,
                $common['seriesTitle'],
                $common['seriesIssn'],
                $common['seriesIdentifier'],
                $salesRights,
                $markets,
            );
        }

        return array_values($products);
    }

    /**
     * Map ISBN-bearing products for Google Books discovery only.
     *
     * Discovery is deliberately independent from feed eligibility. Historical
     * OMP catalogues often contain valid ISBN metadata even when their old
     * proof files, prices, sales-rights rows or availability flags do not match
     * the requirements of the current automated-content feed. Those books must
     * still be discoverable and linkable to existing Google Books volumes.
     *
     * All valid ISBNs attached to the current publication are returned,
     * including print and digital formats. No PDF/EPUB asset, price, sales
     * rights, collection code or feed activation is required.
     *
     * @return BookMetadata[]
     */
    public function mapDiscoverySubmission(Submission $submission, object $context): array
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return [];
        }

        $common = $this->commonMetadata($submission, $publication, $context);
        $products = [];

        foreach ($this->publicationFormats($publication, $context) as $format) {
            foreach ($this->getFormatDiscoveryIsbns13($format) as $isbn13) {
                $isbn10 = IdentifierNormalizer::isbn13To10($isbn13);
                $imprint = method_exists($format, 'getImprint')
                    ? $this->cleanText((string) $format->getImprint())
                    : '';

                // A repeated ISBN on more than one OMP format is still one Google
                // Books identity. Prefer the first non-empty imprint and keep the
                // canonical publication-level metadata.
                if (isset($products[$isbn13])) {
                    if (!$products[$isbn13]->imprint && $imprint !== '') {
                        $products[$isbn13]->imprint = $imprint;
                    }
                    continue;
                }

                $products[$isbn13] = new BookMetadata(
                    (int) $context->getId(),
                    (int) $submission->getId(),
                    (int) $publication->getId(),
                    $isbn13,
                    $isbn10,
                    $common['title'],
                    $common['subtitle'],
                    $common['contributors'],
                    $common['publisher'],
                    $imprint !== '' ? $imprint : null,
                    $common['language'],
                    $common['publicationDate'],
                    $common['description'],
                    $common['licenseUrl'],
                    false,
                    [],
                    [],
                    $common['seriesTitle'],
                    $common['seriesIssn'],
                    $common['seriesIdentifier'],
                    [],
                    [],
                );
            }
        }

        return array_values($products);
    }

    /** @return array<string,mixed> */
    private function commonMetadata(Submission $submission, object $publication, object $context): array
    {
        $language = (string) ($publication->getData('locale') ?: $context->getPrimaryLocale());
        $title = (string) $this->localizedData($publication, 'title', $language);
        $subtitle = (string) $this->localizedData($publication, 'subtitle', $language);
        $description = (string) $this->localizedData($publication, 'abstract', $language);

        $publisher = $this->cleanText((string) ($context->getData('publisher') ?: $context->getName($context->getPrimaryLocale())));
        $date = $this->normalizeDate((string) ($publication->getData('datePublished') ?? ''));
        [$seriesTitle, $seriesIssn, $seriesIdentifier] = $this->seriesMetadata(
            $publication,
            $language,
            (int) $context->getId(),
        );

        return [
            'title' => $this->cleanText($title),
            'subtitle' => ($subtitle = $this->cleanText($subtitle)) !== '' ? $subtitle : null,
            'contributors' => $this->contributors($publication),
            'publisher' => $publisher,
            'language' => $language,
            'publicationDate' => $date,
            'description' => ($description = $this->cleanText($description)) !== '' ? $description : null,
            'licenseUrl' => ($license = trim((string) ($publication->getData('licenseUrl') ?? ''))) !== '' ? $license : null,
            'seriesTitle' => $seriesTitle,
            'seriesIssn' => $seriesIssn,
            'seriesIdentifier' => $seriesIdentifier,
        ];
    }

    /** @return array<int,array{role:string,roles:array<int,string>,name:string,orcid:?string,biography:?string}> */
    private function contributors(object $publication): array
    {
        $contributors = [];
        $locale = (string) ($publication->getData('locale') ?: '');
        $roleMap = [
            'default.groups.name.author' => 'A01',
            'default.groups.name.volumeEditor' => 'B01',
            'default.groups.name.chapterAuthor' => 'A01',
            'default.groups.name.translator' => 'B06',
            'default.groups.name.editor' => 'B21',
        ];

        foreach (($publication->getData('authors') ?? []) as $author) {
            $role = 'A01';
            try {
                $userGroup = UserGroup::find($author->getUserGroupId());
                if ($userGroup && isset($roleMap[$userGroup->nameLocaleKey])) {
                    $role = $roleMap[$userGroup->nameLocaleKey];
                }
            } catch (Exception) {
                // Keep A01 fallback when a user group cannot be resolved.
            }

            $orcid = null;
            if (method_exists($author, 'getOrcid') && $author->getOrcid()) {
                if (!method_exists($author, 'hasVerifiedOrcid') || $author->hasVerifiedOrcid()) {
                    $orcid = (string) $author->getOrcid();
                }
            }
            $contributors[] = [
                'role' => $role,
                'roles' => [$role],
                'name' => $this->cleanText((string) $author->getFullName()),
                'orcid' => $orcid,
                'biography' => $this->contributorBiography($author, $locale),
            ];
        }

        return $this->promoteOrganizersWhenAuthorMissing($contributors);
    }

    private function contributorBiography(object $author, string $locale): ?string
    {
        $value = null;
        if (method_exists($author, 'getLocalizedBiography')) {
            try {
                $value = $author->getLocalizedBiography();
            } catch (Throwable) {
                // Fall through to the canonical localized-data accessors.
            }
        }
        if (!$this->hasValue($value)) {
            $value = $this->localizedData($author, 'biography', $locale);
        }
        if (is_array($value)) {
            $value = $value[$locale] ?? (reset($value) ?: null);
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = $this->cleanText((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * Google Play Books requires at least one A01 contributor for every book.
     * Organized volumes in OMP commonly contain only volume editors/editors,
     * even though those people are the book-level responsible contributors.
     * In that case, expose every organizer as A01 in the Google-facing model.
     * The source OMP contributor groups remain unchanged, and mixed records
     * that already contain an author keep their original editor roles.
     *
     * @param array<int,array{role:string,roles:array<int,string>,name:string,orcid:?string,biography?:?string}> $contributors
     * @return array<int,array{role:string,roles:array<int,string>,name:string,orcid:?string,biography?:?string}>
     */
    private function promoteOrganizersWhenAuthorMissing(array $contributors): array
    {
        foreach ($contributors as $contributor) {
            if (strtoupper(trim((string) ($contributor['role'] ?? ''))) === 'A01') {
                return $contributors;
            }
        }

        foreach ($contributors as &$contributor) {
            $role = strtoupper(trim((string) ($contributor['role'] ?? '')));
            if (!in_array($role, self::ORGANIZER_ROLES, true)) {
                continue;
            }

            $contributor['role'] = 'A01';
            $contributor['roles'] = ['A01'];
        }
        unset($contributor);

        return $contributors;
    }

    /**
     * Load publication formats through OMP's PublicationFormatDAO.
     *
     * OMP 3.5 does not guarantee that a Publication object has a populated
     * `publicationFormats` data property. The public catalogue itself obtains
     * format identifiers from PublicationFormatDAO, so the Google Books plugin
     * must use the same canonical source. A guarded embedded-data fallback is
     * retained only for compatibility with tests/third-party objects.
     *
     * @return array<int,object>
     */
    private function publicationFormats(object $publication, object $context): array
    {
        try {
            $dao = DAORegistry::getDAO('PublicationFormatDAO');
            if ($dao && method_exists($dao, 'getByPublicationId')) {
                $formats = $dao->getByPublicationId((int) $publication->getId(), (int) $context->getId());
                if (is_array($formats) && $formats !== []) {
                    return array_values($formats);
                }
            }
        } catch (Throwable) {
            // Fall back below. Discovery must remain usable on older/custom
            // OMP installations that provide formats through publication data.
        }

        $embedded = $publication->getData('publicationFormats') ?? [];
        return is_array($embedded) ? array_values($embedded) : [];
    }

    /**
     * Return the canonical ISBN-13 used as the Google feed product identity.
     *
     * Prefer the primary ISBN/GTIN identifiers. ONIX code 24 is a legitimate
     * co-publisher ISBN-13 and is accepted as a fallback for historical OMP
     * catalogues where it is the only ISBN recorded for a format.
     */
    private function getFormatIsbn13(object $format): ?string
    {
        $byPriority = [];
        foreach ($this->formatIsbnCandidates($format) as $candidate) {
            $byPriority[$candidate['priority']] ??= $candidate['isbn13'];
        }
        if ($byPriority === []) {
            return null;
        }
        ksort($byPriority, SORT_NUMERIC);
        return reset($byPriority) ?: null;
    }

    /**
     * Discovery checks every ISBN identity carried by the OMP format, including
     * a co-publisher ISBN-13 (ONIX Product identifier type 24). This prevents a
     * book from being reported as ISBN-less merely because its identifier was
     * entered under the co-publisher code.
     *
     * @return string[]
     */
    private function getFormatDiscoveryIsbns13(object $format): array
    {
        $items = $this->formatIsbnCandidates($format);
        usort($items, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $isbns = [];
        foreach ($items as $item) {
            if (!in_array($item['isbn13'], $isbns, true)) {
                $isbns[] = $item['isbn13'];
            }
        }
        return $isbns;
    }

    /**
     * @return array<int,array{isbn13:string,priority:int,type:string}>
     */
    private function formatIsbnCandidates(object $format): array
    {
        $priorities = [
            '15' => 10, // ISBN-13
            '03' => 20, // GTIN-13 - normally the same ISBN product identity
            '24' => 30, // Co-publisher's ISBN-13
            '02' => 40, // Legacy ISBN-10, converted to ISBN-13
        ];
        $items = [];
        $codes = $format->getIdentificationCodes();
        while ($code = $codes->next()) {
            $type = (string) $code->getCode();
            if (!isset($priorities[$type])) {
                continue;
            }
            $isbn13 = IdentifierNormalizer::preferredIsbn13((string) $code->getValue());
            if ($isbn13 === null) {
                continue;
            }
            $items[] = [
                'isbn13' => $isbn13,
                'priority' => $priorities[$type],
                'type' => $type,
            ];
        }
        return $items;
    }

    /** @return array<int,array{kind:string,fileId:int,formatId:int,path:string,mime:string,extension:string,size:int,modified:int,filename:string,directSalesPrice?:float}> */
    private function getFormatAssets(Submission $submission, object $publication, object $format, string $isbn13): array
    {
        $assets = [];
        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([(int) $submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION_FORMAT, [(int) $format->getId()])
            ->getMany();

        foreach ($files as $file) {
            if (method_exists($file, 'getViewable') && !$file->getViewable()) {
                continue;
            }
            // The OMP reader UI treats files with a chapter ID as chapter
            // assets and files without one as whole-book assets. Google must
            // receive only the final whole-book PDF/EPUB for this product.
            $chapterId = method_exists($file, 'getChapterId')
                ? (int) $file->getChapterId()
                : (int) ($file->getData('chapterId') ?? 0);
            if ($chapterId > 0) {
                continue;
            }
            $directSalesPrice = method_exists($file, 'getDirectSalesPrice')
                ? $file->getDirectSalesPrice()
                : $file->getData('directSalesPrice');
            // Mirror the public catalog eligibility rule used by OMP's
            // CatalogBookHandler. A null direct-sales value means that the
            // proof is not available as a published catalog asset.
            if ($directSalesPrice === null) {
                continue;
            }
            $path = (string) $file->getData('path');
            $mime = strtolower((string) ($file->getData('mimetype') ?? ''));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '' && str_contains($mime, 'pdf')) {
                $ext = 'pdf';
            } elseif ($ext === '' && str_contains($mime, 'epub')) {
                $ext = 'epub';
            }
            if (!in_array($ext, ['pdf', 'epub'], true)) {
                continue;
            }

            $fs = app()->get('file')->fs;
            if (!$fs->fileExists($path)) {
                continue;
            }
            $assets[] = [
                'kind' => 'content',
                'fileId' => (int) $file->getData('fileId'),
                'formatId' => (int) $format->getId(),
                'path' => $path,
                'mime' => $mime ?: ($ext === 'pdf' ? 'application/pdf' : 'application/epub+zip'),
                'extension' => $ext,
                'size' => (int) $fs->fileSize($path),
                'modified' => (int) $fs->lastModified($path),
                'filename' => $isbn13 . '.' . $ext,
                'directSalesPrice' => (float) $directSalesPrice,
            ];
        }
        return $this->uniqueAssets($assets);
    }

    /** @return null|array{kind:string,fileId:int,formatId:int,path:string,mime:string,extension:string,size:int,modified:int,filename:string} */
    private function getCoverAsset(object $publication, int $contextId, string $isbn13, string $locale): ?array
    {
        $cover = $this->localizedData($publication, 'coverImage', $locale);
        if (!$cover || empty($cover['uploadName'])) {
            return null;
        }
        $publicFileManager = new PublicFileManager();
        $path = rtrim($publicFileManager->getContextFilesPath($contextId), '/') . '/' . $cover['uploadName'];
        if (!is_file($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }
        $canonicalExt = $ext === 'png' ? 'png' : 'jpg';
        return [
            'kind' => 'cover',
            'fileId' => 0,
            'formatId' => 0,
            'path' => $path,
            'mime' => $canonicalExt === 'png' ? 'image/png' : 'image/jpeg',
            'extension' => $canonicalExt,
            'size' => (int) filesize($path),
            'modified' => (int) filemtime($path),
            'filename' => $isbn13 . '_frontcover.' . $canonicalExt,
        ];
    }

    /**
     * @return array<int,array{type:string,countriesIncluded:array<int,string>,regionsIncluded:array<int,string>,countriesExcluded:array<int,string>,regionsExcluded:array<int,string>}>
     */
    private function getFormatSalesRights(object $format): array
    {
        $rows = [];
        if (!method_exists($format, 'getSalesRights')) {
            return $rows;
        }
        $rights = $format->getSalesRights();
        while ($right = $rights->next()) {
            $regionsIncluded = $this->territoryCodes($right->getRegionsIncluded());
            if ($right->getROWSetting() && !in_array('WORLD', $regionsIncluded, true)) {
                $regionsIncluded[] = 'WORLD';
            }
            $rows[] = [
                'type' => trim((string) $right->getType()),
                'countriesIncluded' => $this->territoryCodes($right->getCountriesIncluded()),
                'regionsIncluded' => $regionsIncluded,
                'countriesExcluded' => $this->territoryCodes($right->getCountriesExcluded()),
                'regionsExcluded' => $this->territoryCodes($right->getRegionsExcluded()),
            ];
        }
        return $this->uniqueRows($rows);
    }

    /**
     * @return array<int,array{amount:string,currency:string,priceType:string,productAvailability:string,countriesIncluded:array<int,string>,regionsIncluded:array<int,string>,countriesExcluded:array<int,string>,regionsExcluded:array<int,string>}>
     */
    private function getFormatMarkets(object $format): array
    {
        $rows = [];
        if (!method_exists($format, 'getMarkets')) {
            return $rows;
        }
        $markets = $format->getMarkets();
        while ($market = $markets->next()) {
            $amount = trim((string) $market->getPrice());
            $rows[] = [
                'amount' => $amount !== '' && is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '',
                'currency' => strtoupper(trim((string) $market->getCurrencyCode())),
                'priceType' => trim((string) $market->getPriceTypeCode()),
                'productAvailability' => method_exists($format, 'getProductAvailabilityCode') && $format->getProductAvailabilityCode()
                    ? trim((string) $format->getProductAvailabilityCode())
                    : '20',
                'countriesIncluded' => $this->territoryCodes($market->getCountriesIncluded()),
                'regionsIncluded' => $this->territoryCodes($market->getRegionsIncluded()),
                'countriesExcluded' => $this->territoryCodes($market->getCountriesExcluded()),
                'regionsExcluded' => $this->territoryCodes($market->getRegionsExcluded()),
            ];
        }
        return $this->uniqueRows($rows);
    }

    /** @param array<int,array<string,mixed>> $markets @return array<int,array{amount:string,currency:string,territory:string}> */
    private function pricesFromMarkets(array $markets): array
    {
        $prices = [];
        foreach ($markets as $market) {
            if ($market['amount'] === '') {
                continue;
            }
            $territory = $market['regionsIncluded'][0] ?? $market['countriesIncluded'][0] ?? '';
            $prices[] = [
                'amount' => $market['amount'],
                'currency' => $market['currency'],
                'territory' => $territory,
            ];
        }
        return $this->uniqueRows($prices);
    }

    /**
     * Infer OMP's direct-access price from the public proof files. A null
     * result means that the source object did not expose a usable price.
     *
     * @param array<int,array<string,mixed>> $assets
     */
    private function assetsAreFree(array $assets): ?bool
    {
        $found = false;
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? '') !== 'content' || !array_key_exists('directSalesPrice', $asset)) {
                continue;
            }
            $found = true;
            if ((float) $asset['directSalesPrice'] > 0) {
                return false;
            }
        }
        return $found ? true : null;
    }

    /** @param array<int,array{amount:string,currency:string,territory:string}> $prices */
    private function pricesAreFree(array $prices): bool
    {
        foreach ($prices as $price) {
            if ((float) $price['amount'] > 0) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private function seriesMetadata(object $publication, string $locale, int $contextId): array
    {
        $seriesId = $publication->getData('seriesId');
        if (!$seriesId) {
            return [null, null, null];
        }
        $series = Repo::section()->get((int) $seriesId, $contextId);
        if (!$series) {
            return [null, null, null];
        }
        $title = $this->localizedData($series, 'title', $locale);
        $rawIssn = null;
        // OMP stores distinct online and print ISSNs for a series. Prefer the
        // online ISSN for digital products, then fall back to print/generic
        // values. Punctuation is normalized below before comparison/export.
        foreach (['getOnlineISSN', 'getPrintISSN'] as $getter) {
            if (method_exists($series, $getter) && $series->{$getter}()) {
                $rawIssn = (string) $series->{$getter}();
                break;
            }
        }
        if ($rawIssn === null && method_exists($series, 'getData')) {
            foreach (['onlineIssn', 'printIssn', 'issn'] as $key) {
                if ($series->getData($key)) {
                    $rawIssn = (string) $series->getData($key);
                    break;
                }
            }
        }
        // The normalizer deliberately ignores punctuation. 2049-3630,
        // 2049.3630, 2049 3630 and 20493630 resolve to one canonical ISSN.
        $issn = $rawIssn ? IdentifierNormalizer::normalizeIssn($rawIssn) : null;
        $title = ($title = $this->cleanText((string) $title)) !== '' ? $title : null;

        // Google's ONIX profile expects every Collection composite to carry
        // a CollectionIdentifier. When the series has no ISSN, preserve a
        // publisher-neutral, stable OMP identifier rather than omitting the
        // identifier or inventing an ISSN. The context prefix prevents two
        // presses in one OMP installation from colliding on the same series ID.
        $seriesIdentifier = $title !== null
            ? 'OMP' . $contextId . 'S' . (int) $seriesId
            : null;

        return [$title, $issn, $seriesIdentifier];
    }

    /**
     * Read a localized OMP setting in the publication's own locale. This
     * avoids exporting a title, synopsis, series name or cover selected only
     * because an editor happened to view the dashboard in another UI locale.
     */
    private function localizedData(object $object, string $field, string $locale): mixed
    {
        $value = null;
        if (method_exists($object, 'getData')) {
            try {
                $value = $object->getData($field, $locale);
            } catch (\ArgumentCountError) {
                $value = $object->getData($field);
            }
            if ($this->hasValue($value)) {
                return $value;
            }
        }

        if (method_exists($object, 'getLocalizedData')) {
            $value = $object->getLocalizedData($field);
            if ($this->hasValue($value)) {
                return $value;
            }
        }

        return $value;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && $value !== '';
    }

    /**
     * Convert OMP's localized HTML metadata into plain ONIX text exactly once.
     * Decoding entities before XML escaping prevents values such as &amp; from
     * becoming &amp;amp; in the generated message.
     */
    private function cleanText(string $value): string
    {
        $value = preg_replace('/<\s*\/?\s*(?:p|div|br|li|h[1-6])\b[^>]*>/i', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($date))->format('Ymd');
        } catch (Exception) {
            return '';
        }
    }

    /** @param mixed $values @return string[] */
    private function territoryCodes(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }
        if (!is_array($values)) {
            $values = preg_split('/[\s,;]+/', (string) $values) ?: [];
        }
        $codes = [];
        foreach ($values as $value) {
            $value = strtoupper(trim((string) $value));
            if ($value !== '' && preg_match('/^[A-Z0-9]{2,8}$/', $value)) {
                $codes[] = $value;
            }
        }
        return array_values(array_unique($codes));
    }

    /** @param array<int,array<string,mixed>> $assets */
    private function uniqueAssets(array $assets): array
    {
        $unique = [];
        foreach ($assets as $asset) {
            $key = (string) $asset['filename'];
            if (!isset($unique[$key]) || (int) ($asset['modified'] ?? 0) >= (int) ($unique[$key]['modified'] ?? 0)) {
                $unique[$key] = $asset;
            }
        }
        ksort($unique);
        return array_values($unique);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function uniqueRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $unique[$key] = $row;
        }
        ksort($unique);
        return array_values($unique);
    }
}
