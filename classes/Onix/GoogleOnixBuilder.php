<?php

declare(strict_types=1);

/**
 * Google-specific ONIX 3.0 profile for Open Monograph Press.
 *
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Onix;

use APP\plugins\generic\googleBooks\classes\Model\BookMetadata;
use APP\plugins\generic\googleBooks\classes\Util\IdentifierNormalizer;
use APP\plugins\generic\googleBooks\classes\Util\LanguageMapper;
use APP\plugins\generic\googleBooks\classes\Util\Xml;
use DateTimeImmutable;
use DateTimeZone;

final class GoogleOnixBuilder
{
    /** @param BookMetadata[] $books */
    public function build(
        array $books,
        string $senderName,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?DateTimeImmutable $sentAt = null,
        bool $includeSupplyDetail = true,
    ): string {
        $sentAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ONIXMessage xmlns="http://ns.editeur.org/onix/3.0/reference" release="3.0">' . "\n";
        $xml .= "  <Header>\n";
        $xml .= "    <Sender>\n";
        $xml .= Xml::element('SenderName', $senderName, 3);
        $xml .= Xml::element('ContactName', $contactName, 3);
        $xml .= Xml::element('EmailAddress', $contactEmail, 3);
        $xml .= "    </Sender>\n";
        $xml .= "    <Addressee>\n";
        $xml .= Xml::element('AddresseeName', 'Google', 3);
        $xml .= "    </Addressee>\n";
        $xml .= Xml::element('SentDateTime', $sentAt->format('Ymd\\THis\\Z'), 2);
        $xml .= "  </Header>\n";

        foreach ($books as $book) {
            $xml .= $this->buildProduct($book, $includeSupplyDetail);
        }

        $xml .= "</ONIXMessage>\n";
        return $xml;
    }

    private function buildProduct(BookMetadata $book, bool $includeSupplyDetail): string
    {
        $isbn13 = IdentifierNormalizer::preferredIsbn13($book->isbn13) ?? $book->isbn13;
        $seriesTitle = trim((string) $book->seriesTitle);
        $seriesIssn = $book->seriesIssn !== null
            ? IdentifierNormalizer::normalizeIssn($book->seriesIssn)
            : null;
        $seriesIdentifier = $this->seriesIdentifier($book->seriesIdentifier);

        $xml = "  <Product>\n";
        $xml .= Xml::element('RecordReference', $isbn13, 2);
        $xml .= Xml::element('NotificationType', '03', 2);
        $xml .= "    <ProductIdentifier>\n";
        $xml .= Xml::element('ProductIDType', '15', 3);
        $xml .= Xml::element('IDValue', $isbn13, 3);
        $xml .= "    </ProductIdentifier>\n";

        $xml .= "    <DescriptiveDetail>\n";
        $xml .= Xml::element('ProductComposition', '00', 3);
        $xml .= Xml::element('ProductForm', 'EA', 3);
        foreach ($this->productFormDetails($book) as $detail) {
            $xml .= Xml::element('ProductFormDetail', $detail, 3);
        }

        if ($seriesTitle !== '') {
            $xml .= "      <Collection>\n";
            $xml .= Xml::element('CollectionType', '10', 4);
            if ($seriesIssn !== null) {
                $xml .= "        <CollectionIdentifier>\n";
                $xml .= Xml::element('CollectionIDType', '02', 5);
                $xml .= Xml::element('IDValue', $seriesIssn, 5);
                $xml .= "        </CollectionIdentifier>\n";
            } elseif ($seriesIdentifier !== null) {
                $xml .= "        <CollectionIdentifier>\n";
                $xml .= Xml::element('CollectionIDType', '01', 5);
                $xml .= Xml::element('IDTypeName', 'Publisher Series ID', 5);
                $xml .= Xml::element('IDValue', $seriesIdentifier, 5);
                $xml .= "        </CollectionIdentifier>\n";
            }
            $xml .= "        <TitleDetail>\n";
            $xml .= Xml::element('TitleType', '01', 5);
            $xml .= "          <TitleElement>\n";
            $xml .= Xml::element('TitleElementLevel', '02', 6);
            $xml .= Xml::element('TitleText', $seriesTitle, 6);
            $xml .= "          </TitleElement>\n";
            $xml .= "        </TitleDetail>\n";
            $xml .= "      </Collection>\n";
        }

        $xml .= "      <TitleDetail>\n";
        $xml .= Xml::element('TitleType', '01', 4);
        $xml .= "        <TitleElement>\n";
        $xml .= Xml::element('TitleElementLevel', '01', 5);
        $xml .= Xml::element('TitleText', $book->title, 5);
        $xml .= Xml::element('Subtitle', $book->subtitle, 5);
        $xml .= "        </TitleElement>\n";
        $xml .= "      </TitleDetail>\n";

        $sequence = 1;
        foreach ($book->contributors as $contributor) {
            $xml .= "      <Contributor>\n";
            $xml .= Xml::element('SequenceNumber', (string) $sequence++, 4);
            $role = $this->contributorRole($contributor);
            if ($role !== null) {
                $xml .= Xml::element('ContributorRole', $role, 4);
            }
            $orcid = !empty($contributor['orcid'])
                ? IdentifierNormalizer::formatOrcid((string) $contributor['orcid'])
                : null;
            if ($orcid !== null) {
                $xml .= "        <NameIdentifier>\n";
                $xml .= Xml::element('NameIDType', '21', 5);
                $xml .= Xml::element('IDValue', $orcid, 5);
                $xml .= "        </NameIdentifier>\n";
            }
            $xml .= Xml::element('PersonName', $contributor['name'], 4);
            $xml .= Xml::element('BiographicalNote', $contributor['biography'] ?? null, 4);
            $xml .= "      </Contributor>\n";
        }

        if ($book->relatedProducts === []) {
            $xml .= Xml::element('EditionType', 'DGO', 3);
        }

        $xml .= "      <Language>\n";
        $xml .= Xml::element('LanguageRole', '01', 4);
        $xml .= Xml::element('LanguageCode', LanguageMapper::toOnix($book->language), 4);
        $xml .= "      </Language>\n";

        foreach ($book->extents as $extent) {
            $type = trim((string) ($extent['type'] ?? ''));
            $value = trim((string) ($extent['value'] ?? ''));
            $unit = trim((string) ($extent['unit'] ?? ''));
            if ($type === '' || $value === '' || $unit === '') { continue; }
            $xml .= "      <Extent>\n";
            $xml .= Xml::element('ExtentType', $type, 4);
            $xml .= Xml::element('ExtentValue', $value, 4);
            $xml .= Xml::element('ExtentUnit', $unit, 4);
            $xml .= "      </Extent>\n";
        }

        foreach ($book->subjects as $subject) {
            $scheme = trim((string) ($subject['scheme'] ?? ''));
            $code = trim((string) ($subject['code'] ?? ''));
            $heading = trim((string) ($subject['heading'] ?? ''));
            if ($scheme === '' || $code === '') { continue; }
            $xml .= "      <Subject>\n";
            $xml .= Xml::element('SubjectSchemeIdentifier', $scheme, 4);
            if ($code !== '') { $xml .= Xml::element('SubjectCode', $code, 4); }
            if ($heading !== '') { $xml .= Xml::element('SubjectHeadingText', $heading, 4); }
            $xml .= "      </Subject>\n";
        }
        $xml .= "    </DescriptiveDetail>\n";

        if ($book->description) {
            $xml .= "    <CollateralDetail>\n";
            $xml .= "      <TextContent>\n";
            $xml .= Xml::element('TextType', '03', 4);
            $xml .= Xml::element('ContentAudience', '00', 4);
            $xml .= Xml::element('Text', strip_tags($book->description), 4);
            $xml .= "      </TextContent>\n";
            $xml .= "    </CollateralDetail>\n";
        }

        $xml .= "    <PublishingDetail>\n";
        if ($book->imprint) {
            $xml .= "      <Imprint>\n";
            $xml .= Xml::element('ImprintName', $book->imprint, 4);
            $xml .= "      </Imprint>\n";
        }
        $xml .= "      <Publisher>\n";
        $xml .= Xml::element('PublishingRole', '01', 4);
        $xml .= Xml::element('PublisherName', $book->publisher, 4);
        $xml .= "      </Publisher>\n";
        $xml .= Xml::element('PublishingStatus', '04', 3);
        $xml .= "      <PublishingDate>\n";
        $xml .= Xml::element('PublishingDateRole', '01', 4);
        $xml .= Xml::element('Date', $book->publicationDate, 4, ['dateformat' => '00']);
        $xml .= "      </PublishingDate>\n";
        if ($includeSupplyDetail) {
            $xml .= $this->buildSalesRights($book);
        }
        $xml .= "    </PublishingDetail>\n";

        $relatedProducts = [];
        foreach ($book->relatedProducts as $related) {
            $relatedIsbn = IdentifierNormalizer::preferredIsbn13((string) ($related['isbn13'] ?? ''));
            $relationCode = trim((string) ($related['relationCode'] ?? '06'));
            if ($relatedIsbn === null || $relatedIsbn === $isbn13 || !preg_match('/^\d{2}$/', $relationCode)) { continue; }
            $relatedProducts[$relationCode . ':' . $relatedIsbn] = [$relationCode, $relatedIsbn];
        }
        if ($relatedProducts !== []) {
            ksort($relatedProducts);
            $xml .= "    <RelatedMaterial>\n";
            foreach ($relatedProducts as [$relationCode, $relatedIsbn]) {
                $xml .= "      <RelatedProduct>\n";
                $xml .= Xml::element('ProductRelationCode', $relationCode, 4);
                $xml .= "        <ProductIdentifier>\n";
                $xml .= Xml::element('ProductIDType', '15', 5);
                $xml .= Xml::element('IDValue', $relatedIsbn, 5);
                $xml .= "        </ProductIdentifier>\n";
                $xml .= "      </RelatedProduct>\n";
            }
            $xml .= "    </RelatedMaterial>\n";
        }

        if ($includeSupplyDetail) {
            $xml .= $this->buildProductSupply($book);
        }
        $xml .= "  </Product>\n";

        return $xml;
    }


    private function seriesIdentifier(?string $identifier): ?string
    {
        $identifier = strtoupper(trim((string) $identifier));
        if ($identifier === '' || !preg_match('/^[A-Z0-9._:-]{1,100}$/', $identifier)) {
            return null;
        }
        return $identifier;
    }

    /** @param array<string,mixed> $contributor */
    private function contributorRole(array $contributor): ?string
    {
        $primary = strtoupper(trim((string) ($contributor['role'] ?? '')));
        if ($primary !== '') {
            return $primary;
        }

        $rawRoles = $contributor['roles'] ?? [];
        if (!is_array($rawRoles)) {
            $rawRoles = [$rawRoles];
        }
        foreach ($rawRoles as $role) {
            $role = strtoupper(trim((string) $role));
            if ($role !== '') {
                return $role;
            }
        }
        return null;
    }

    private function buildSalesRights(BookMetadata $book): string
    {
        $xml = '';
        foreach ($book->salesRights as $right) {
            $xml .= "      <SalesRights>\n";
            $xml .= Xml::element('SalesRightsType', (string) ($right['type'] ?? ''), 4);
            $xml .= $this->buildTerritory($right, 4);
            $xml .= "      </SalesRights>\n";
        }
        return $xml;
    }

    private function buildProductSupply(BookMetadata $book): string
    {
        $markets = $this->eligibleMarkets($book);
        if ($markets === [] && $book->freeOfCharge) {
            foreach ($book->salesRights as $right) {
                if (in_array((string) ($right['type'] ?? ''), ['01', '02'], true)) {
                    $markets[] = [
                        'amount' => '',
                        'currency' => '',
                        'priceType' => '',
                        'productAvailability' => '20',
                        'countriesIncluded' => $right['countriesIncluded'] ?? [],
                        'regionsIncluded' => $right['regionsIncluded'] ?? [],
                        'countriesExcluded' => $right['countriesExcluded'] ?? [],
                        'regionsExcluded' => $right['regionsExcluded'] ?? [],
                    ];
                }
            }
        }

        $xml = '';
        foreach ($markets as $market) {
            $xml .= "    <ProductSupply>\n";
            $xml .= "      <Market>\n";
            $xml .= $this->buildTerritory($market, 4);
            $xml .= "      </Market>\n";
            $xml .= "      <MarketPublishingDetail>\n";
            $xml .= Xml::element('MarketPublishingStatus', '04', 4);
            $xml .= "      </MarketPublishingDetail>\n";
            $xml .= "      <SupplyDetail>\n";
            $xml .= "        <Supplier>\n";
            $xml .= Xml::element('SupplierRole', '09', 5);
            $xml .= Xml::element('SupplierName', $book->publisher, 5);
            $xml .= "        </Supplier>\n";
            $xml .= Xml::element('ProductAvailability', (string) ($market['productAvailability'] ?? '20'), 4);
            if ($book->freeOfCharge) {
                $xml .= Xml::element('UnpricedItemType', '01', 4);
            } else {
                $xml .= "        <Price>\n";
                $xml .= Xml::element('PriceType', (string) ($market['priceType'] ?? ''), 5);
                $xml .= Xml::element('PriceAmount', (string) ($market['amount'] ?? ''), 5);
                $xml .= Xml::element('CurrencyCode', (string) ($market['currency'] ?? ''), 5);
                $xml .= $this->buildTerritory($market, 5);
                $xml .= "        </Price>\n";
            }
            $xml .= "      </SupplyDetail>\n";
            $xml .= "    </ProductSupply>\n";
        }
        return $xml;
    }

    /** @return array<int,array<string,mixed>> */
    private function eligibleMarkets(BookMetadata $book): array
    {
        $markets = [];
        foreach ($book->markets as $market) {
            if (empty($market['countriesIncluded']) && empty($market['regionsIncluded'])) {
                continue;
            }
            if (!$book->freeOfCharge && ((string) ($market['amount'] ?? '') === '' || (float) $market['amount'] <= 0)) {
                continue;
            }
            $markets[] = $market;
        }
        return $markets;
    }

    /** @param array<string,mixed> $territory */
    private function buildTerritory(array $territory, int $indent): string
    {
        $xml = str_repeat('  ', $indent) . "<Territory>\n";
        $xml .= Xml::element('CountriesIncluded', $this->codes($territory['countriesIncluded'] ?? []), $indent + 1);
        $xml .= Xml::element('RegionsIncluded', $this->codes($territory['regionsIncluded'] ?? []), $indent + 1);
        $xml .= Xml::element('CountriesExcluded', $this->codes($territory['countriesExcluded'] ?? []), $indent + 1);
        $xml .= Xml::element('RegionsExcluded', $this->codes($territory['regionsExcluded'] ?? []), $indent + 1);
        $xml .= str_repeat('  ', $indent) . "</Territory>\n";
        return $xml;
    }

    /** @param mixed $codes */
    private function codes(mixed $codes): ?string
    {
        if (!is_array($codes)) {
            return null;
        }
        $codes = array_values(array_filter(array_map(static fn ($v): string => strtoupper(trim((string) $v)), $codes)));
        return $codes ? implode(' ', $codes) : null;
    }

    /** @return string[] */
    private function productFormDetails(BookMetadata $book): array
    {
        $details = [];
        foreach ($book->assets as $asset) {
            if ($asset['extension'] === 'epub') {
                $details[] = 'E101';
            } elseif ($asset['extension'] === 'pdf') {
                $details[] = 'E107';
            }
        }
        return array_values(array_unique($details ?: ['E107']));
    }
}
