# Google Books Integration for OMP 0.1.2.6

Maintenance release focused on Google Play Books final ONIX verification and reliable delivery of large publisher catalogues.

## Google ONIX delivery integrity

- Builds the complete ONIX document before any response bytes are written.
- Runs `GoogleOnixValidator::validateXml()` before exposing bibliographic, rights or validation ONIX.
- Verifies the generated number of `<Product>` and `</Product>` elements against the number of products selected for the feed.
- Refuses HTTP delivery if the final XML is missing `</ONIXMessage>` or contains unbalanced Product composites.
- Clears buffered OMP/theme/debug output before the XML response so a warning, BOM or template fragment cannot contaminate the ONIX payload.
- Disables PHP zlib output compression for the ONIX response when the runtime permits it and sends `Cache-Control: ... no-transform` together with an exact byte `Content-Length`.
- Adds a dedicated 150-product regression fixture to reproduce the scale of the CLAEC catalogue that exposed the previous truncation report.

## Stable Google validation URL

- The validation directory now exposes the permanent filename `googlebooksvalidation.xml` instead of coupling the public filename to the selected OMP submission ID.
- Existing `googlebooksvalidation<submissionId>.xml` URLs remain valid compatibility aliases and serve the current validation sample, so a URL already supplied to Google does not break when the anchor monograph changes.
- The validation sample continues to contain up to 10 real, published, metadata-valid ISBN products from the press and never fabricates production ISBN records.

## Google Play Books feedback

- Keeps the 0.1.2.5 fix that emits exactly one `ContributorRole` per `Contributor` composite.
- Keeps free-title handling publisher-neutral and data-driven. Products that OMP identifies as free continue to emit `<UnpricedItemType>01</UnpricedItemType>` and no `<Price>` composite. Paid markets continue to emit ONIX Price data.
- Adds regression coverage confirming that 150 free products generate 150 `UnpricedItemType 01` elements and zero Price composites.

## Language codes

- Updates ONIX language mappings to canonical ISO 639-2 terminology codes where bibliographic and terminology aliases differ (`deu`, `fra`, `nld`, `ces`, `ell`, `ron`, `slk`, `zho`).
- Adds Guarani (`gn` -> `grn`) support.

## Compatibility

- OMP 3.5.x / primary target OMP 3.5.0-5 LTS.
- PHP 8.x.
- No database migration is required for this maintenance release.
- Existing HTTP Basic credentials, collection routing, SFTP/FTP/GCS settings and encrypted secrets remain unchanged.
