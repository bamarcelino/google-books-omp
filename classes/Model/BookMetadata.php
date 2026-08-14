<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Model;

final class BookMetadata
{
    /**
     * @param array<int,array{role?:string,roles?:array<int,string>,name:string,orcid:?string}> $contributors
     * @param array<int,array{amount:string,currency:string,territory:string}> $prices
     * @param array<int,array{type:string,countriesIncluded:array<int,string>,regionsIncluded:array<int,string>,countriesExcluded:array<int,string>,regionsExcluded:array<int,string>}> $salesRights
     * @param array<int,array{amount:string,currency:string,priceType:string,productAvailability:string,countriesIncluded:array<int,string>,regionsIncluded:array<int,string>,countriesExcluded:array<int,string>,regionsExcluded:array<int,string>}> $markets
     * @param array<int,array{kind:string,fileId:int,formatId:int,path:string,mime:string,extension:string,size:int,modified:int,filename:string,directSalesPrice?:float}> $assets
     */
    public function __construct(
        public int $contextId,
        public int $submissionId,
        public int $publicationId,
        public string $isbn13,
        public ?string $isbn10,
        public string $title,
        public ?string $subtitle,
        public array $contributors,
        public string $publisher,
        public ?string $imprint,
        public string $language,
        public string $publicationDate,
        public ?string $description,
        public ?string $licenseUrl,
        public bool $freeOfCharge,
        public array $prices,
        public array $assets,
        public ?string $seriesTitle = null,
        public ?string $seriesIssn = null,
        public ?string $seriesIdentifier = null,
        public array $salesRights = [],
        public array $markets = [],
    ) {
    }

    public function metadataFingerprint(): string
    {
        $payload = [
            'isbn13' => $this->isbn13,
            'isbn10' => $this->isbn10,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'contributors' => $this->contributors,
            'publisher' => $this->publisher,
            'imprint' => $this->imprint,
            'language' => $this->language,
            'publicationDate' => $this->publicationDate,
            'description' => $this->description,
            'licenseUrl' => $this->licenseUrl,
            'freeOfCharge' => $this->freeOfCharge,
            'prices' => $this->prices,
            'salesRights' => $this->salesRights,
            'markets' => $this->markets,
            'seriesTitle' => $this->seriesTitle,
            'seriesIssn' => $this->seriesIssn,
            'seriesIdentifier' => $this->seriesIdentifier,
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function contentFingerprint(): string
    {
        $assets = $this->assets;
        usort($assets, fn (array $a, array $b): int => [$a['extension'], $a['fileId']] <=> [$b['extension'], $b['fileId']]);
        return hash('sha256', json_encode($assets, JSON_UNESCAPED_SLASHES));
    }
}
