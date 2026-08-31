# Google Books Integration for OMP 0.1.2.12

This maintenance release improves reconciliation of newly ingested Google Books records whose storefront pages are already live while the public Books API ISBN field index still returns no result.

## Changes

- Retains the canonical `isbn:` lookup as the primary discovery method.
- Adds a plain ISBN fallback followed by an exact-title lookup when necessary.
- Requires every candidate to contain the exact normalized ISBN in `industryIdentifiers`, regardless of how it was retrieved.
- Supplies the OMP book title to the discovery client.
- Uses `books.google.com/books?id=<VolumeID>` for the Books action even when the API's informational link points to Google Play; the API acquisition link remains exclusive to the Play action.
- Labels unresolved records as **not returned by the Google Books API**, avoiding the inaccurate implication that they do not exist on Google.

No database migration is required. Install this release as an in-place upgrade, clear OMP caches, and run **Discover catalogue in Google Books** again.

The broader lookup improves coverage but cannot guarantee that every Google storefront record is immediately exposed by the public Volumes API. Records the API does not return under any supported query remain unresolved and are retried according to the plugin's normal schedule.
