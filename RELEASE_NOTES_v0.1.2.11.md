# Google Books Integration for OMP 0.1.2.11

Release date: 2026-09-01

## Public Google Books and Google Play Books actions

- Replaces the public ISBN/Volume ID line with visible, responsive action buttons.
- Keeps **View on Google Books** for every exact, unambiguous discovered volume.
- Adds **View on Google Play Books** only when the Books API confirms `saleInfo.buyLink`, `isEbook` and an eligible `saleability` value.
- Uses accessible link text, keyboard focus styling and lightweight inline vector icons without external image requests.
- Adds English, Spanish and Brazilian Portuguese labels.

## Google Play availability storage

- Stores the optional acquisition URL, storefront state and e-book flag returned by exact ISBN discovery.
- Adds nullable fields through the existing idempotent migration, preserving all current Google Books links, feed settings, credentials and synchronization history.
- Requires one catalogue discovery after upgrade to enrich records linked by older releases.

## Free-book pricing

Free products remain encoded as `<UnpricedItemType>01</UnpricedItemType>` with no `<Price>` composite or zero `<PriceAmount>`. This is the ONIX representation confirmed during direct Google support validation; the release does not invent retail prices.

## Compatibility and validation

- Open Monograph Press 3.5.x
- Primary target: OMP 3.5.0-5 LTS
- Database migration: three nullable Google Play discovery fields, applied idempotently
- Complete suite: 748 behavioral and source-contract assertions
