# Google Books Integration for OMP 0.1.2.13

This maintenance release reconciles Google Books records that are already visible on the public Books/Play storefront but are still absent from the `volumes.list?q=isbn:` search index.

## Changes

- Adds Google's public `vid=ISBN` bibliographic resolver after the primary API ISBN query.
- Requires the resolver page to contain the exact normalized ISBN before storing its Volume ID.
- Captures the public Books link, Play acquisition link and e-book signal from an exact resolved record.
- Can recover an exact public record after an API quota error.
- Removes the quota-heavy plain-ISBN and title API list fallbacks.
- Adds informative per-ISBN run details for unresolved books.
- Guarantees non-empty failure diagnostics even when an underlying exception has no message.

No database migration is required. Install as an in-place upgrade, clear OMP caches, and run **Discover catalogue in Google Books** again.
