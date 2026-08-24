# Google Books Integration for OMP 0.1.2.4

Release date: 2026-08-24

## Live Google onboarding fixes

### Authenticated HTTPS feed can coexist with SFTP and other push transports

The virtual `/googlebooksfeed/` surface is no longer disabled when a press selects Google-provided SFTP Dropbox, publisher SFTP, FTP/FTPS, Google Cloud Storage or protected local export as its primary transport.

When the existing `feedEnabled` switch is enabled, the following authenticated routes remain available independently from the selected push/staging transport:

- `onix/validate/`
- `onix/<COLLECTION>-full/`
- `onix/<COLLECTION>-rights/`
- `ebooks/`
- `ebooks/<COLLECTION>/`

HTTP Basic authentication remains mandatory. Collection-code validation and all existing feed/content eligibility checks are unchanged.

This resolves the production symptom where the feed root and ONIX validation directory were reachable but full ONIX, rights ONIX and eBook directories returned `404 Not Found` whenever a non-HTTP delivery mode was selected.

### Real multi-product ONIX validation sample

The initial ONIX validation sample now starts with the real published OMP title selected by the manager and automatically supplements it with other real published products from the same press until it contains up to 10 unique metadata-valid ISBN records.

The validation sample:

- uses published OMP catalogue data only;
- skips duplicate ISBNs;
- skips products that fail metadata-only Google ONIX validation;
- remains metadata-only and does not require PDF/EPUB assets;
- never fabricates ISBNs or synthetic catalogue products;
- returns fewer than 10 records only when the press has fewer than 10 eligible real products.

This is intentionally safer than generating fictitious examples because an ONIX validation file can be ingested accidentally and must not create false catalogue records.

## Retained 0.1.2.3 transport hardening

Release 0.1.2.4 retains the SFTP endpoint normalization and staged diagnostics introduced in 0.1.2.3, including bare-host/full-URL normalization, non-destructive Dropbox testing, DNS/TCP/SSH/authentication diagnostics, cURL and OS errno reporting, resolved/primary IP reporting and safe IPv4 fallback.

## Validation

The complete OMP 3.5 validation suite passed on GitHub Actions after the changes:

- 218 core behavior/authentication/secret/delivery assertions
- 42 repository assertions
- 24 mapper/DAO/ISBN assertions
- 16 settings migration assertions
- 28 SFTP endpoint/diagnostic assertions
- 221 package/security/source-contract checks
- 55 OMP 3.5 compatibility smoke assertions
- 40 dashboard operation/persistence/queue smoke assertions

Total: **644/644 checks passed**.

The package regression suite adds explicit contracts that prevent the HTTPS feed from being coupled again to `DeliveryConfig::mode()` and require the real-product validation target of up to 10 unique ISBN records.

## Upgrade

This is a maintenance update from 0.1.2.3 and does not require a database migration. Existing collection codes, HTTP Basic credentials, encrypted SFTP/FTP/GCS secrets, Google Books API configuration, delivery state and synchronization history are preserved.
