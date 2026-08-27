# Google Books Integration for OMP 0.1.2.7

Maintenance release responding to Google Play Books' second ONIX verification review.

## Google Play commercial validation sample

- The canonical `googlebooksvalidation.xml` sample now contains the commercial ONIX sections Google requires on every Product, not only bibliographic metadata.
- Every validation Product must contain at least one valid `SalesRights` composite with an included territory and SalesRightsType `01` or `02`.
- Every validation Product is emitted with `ProductSupply`, `Market`, `MarketPublishingDetail` and `SupplyDetail`.
- Free-of-charge publications retain `UnpricedItemType 01`; the plugin does not invent a retail price for open/free books.
- Paid publications continue to require a positive OMP market price, currency, supported ONIX PriceType and territory.
- Validation records are drawn only from real published OMP products. Synthetic ISBNs and fictitious products are not generated.
- The validation endpoint now refuses to generate a sample unless at least 10 commercially complete real products are available, matching Google's minimum batch requirement.

## Validation hardening

- Adds `validateCommercialMetadataBook()` so Google validation records are checked for bibliographic and commercial completeness without incorrectly requiring content-file assets in the ONIX sample.
- Adds `validateCommercialXml()` to verify Google's ingestion-profile requirements on every generated Product, including SalesRights, ProductSupply, Market/Territory, MarketPublishingStatus, Supplier, ProductAvailability and either UnpricedItemType or Price.
- Commercial profile validation runs before the HTTP response is emitted for both the validation sample and the live rights feed.
- Adds a dedicated 10-product regression fixture proving that free titles contain 10 SalesRights, 10 ProductSupply composites and 10 `UnpricedItemType 01` elements with no Price composites.

## Compatibility

- OMP 3.5.x / PHP 8.x.
- No database migration.
- Existing settings, collection codes, crawler credentials and transport credentials are preserved during an in-place upgrade.
- The permanent validation URL introduced in 0.1.2.6 remains unchanged, and legacy `googlebooksvalidation<ID>.xml` aliases continue to resolve.
