# Google Books Integration for OMP 0.1.2.2 - Validation Report

**Target:** Open Monograph Press 3.5.x, validated against OMP/PKP 3.5.0-5 public contracts  
**Author:** Bruno Cesar Alves Marcelino  
**Organization:** Scientia International  
**Release date:** 2026-08-14

## Scope

Release 0.1.2.2 retains the 0.1.2.x transport-neutral delivery layer and dashboard navigation repairs while hardening Google Books API-key storage and preserving the prior OMP 3.5 repairs for HTTP Basic authentication diagnostics, dashboard actions, historical ISBN discovery, localization, Google Books API error handling, queue isolation and public identifier placement.

Validation covers plugin code, OMP-facing contracts, identifier normalization, Google Books discovery behavior, ONIX generation, delivery-manifest generation, reversible outbound-secret protection, database state, transport configuration, localization, queue integration and distribution archives. It does not claim that Google has accepted a real publisher feed or credentials; Google-side onboarding remains external.


## Dashboard navigation reliability

The dashboard now uses three independent safeguards: versioned asset URLs to prevent stale JavaScript after in-place upgrades, direct template loading of the same idempotent script as a fallback to backend asset registration, and native radio/CSS tab switching that requires no JavaScript. The JavaScript bootstrap also runs immediately when loaded after `DOMContentLoaded`. The currently saved delivery transport panel is server-rendered active before scripting runs.

## Multi-transport delivery verified

The release exposes six delivery modes from one canonical OMP source of truth:

- HTTP/HTTPS pull through authenticated virtual OMP feed routes;
- Google-provided SFTP Dropbox push;
- publisher-controlled SFTP push;
- publisher-controlled FTP or FTPS push;
- Google Cloud Storage staging;
- protected local export under OMP `files_dir`.

The Google-facing logical tree is generated independently from the transport:

```text
onix/
    validate/
    <COLLECTION>-full/
    <COLLECTION>-rights/

ebooks/
    <COLLECTION>/
```

The delivery manifest can independently enable the validation sample, ONIX bibliographic feed, ONIX rights feed, and eBook/content assets. Push/staging delivery records path fingerprints in `google_books_delivery_files`, so unchanged resources can be skipped. Google-provided SFTP Dropbox is deliberately excluded from destructive stale-file cleanup.

## Authentication and secret protection

The dashboard now separates Google/API and remote-transport authentication from delivery configuration.

HTTP/HTTPS pull retains the 0.1.1.6 secret-free diagnostic and the compatibility fallbacks for native PHP auth variables, raw server Authorization variables, request headers, `$_ENV`, `getenv()` and FastCGI variants. Incoming crawler passwords continue to use `password_hash()` / `password_verify()`.

SFTP, FTP/FTPS and GCS are outbound transports, so their credentials must be recoverable. `SecretStore` encrypts those values with AES-256-GCM using a key derived from OMP `general.app_key`. Release 0.1.2.2 applies the same principle to the Google Books API key using a separate versioned `gbapi:v1` envelope and AAD domain. Pre-0.1.2.2 plaintext API keys remain readable until encryption succeeds, then the legacy plaintext value is cleared. Tests verify round-trip, tamper rejection, encrypted persistence, and absence of new plaintext API-key writes. The dashboard never renders stored secret values back to the manager.

## Transport/runtime capability checks

`TransportCapabilities` detects whether the current PHP runtime can support the selected transport:

- cURL plus SFTP protocol support for SFTP modes;
- PHP FTP extension for FTP;
- `ftp_ssl_connect()` for FTPS;
- cURL HTTPS plus OpenSSL signing for Google Cloud Storage;
- local export unconditionally;
- ZipArchive as an additional runtime capability indicator.

Readiness checks combine runtime capabilities, selected payloads, collection code, feed enablement, and required connection/authentication fields before automatic delivery is considered ready.

## Delivery queue and incremental state

`DeliveryJob` runs push/staging delivery in OMP's background queue. Catalogue and per-submission synchronization dispatch it when the selected mode is not HTTP pull and the source state changed or a force operation was requested.

The delivery repository stores transport identity, relative remote path, fingerprint, size, state, last error and delivery timestamps. Repeated runs skip matching fingerprints unless delivery is forced. Publisher-controlled inventory destinations, GCS and local export can remove stale paths; Google SFTP Dropbox does not.

Connection-test and delivery diagnostics are persisted without storing transport secrets. Low-level error strings are sanitized for Authorization/Bearer material and URL userinfo.

## OMP 3.5.0-5 queue-job inheritance compatibility

OMP 3.5.0-5 uses the PKP `BaseJob` class whose `$tries` property is untyped. The plugin retains the 0.1.1.3 compatibility repair by keeping `$tries` untyped in all Google Books jobs, including the new `DeliveryJob`. The OMP smoke fixture mirrors the parent declarations and package validation rejects incompatible typed overrides.

## OMP 3.5 publication-format/ISBN discovery

`OmpBookMapper` loads publication formats with OMP's `PublicationFormatDAO::getByPublicationId()` and recognizes valid ISBN identities from ONIX List 5 types `15` (ISBN-13), `03` (GTIN-13), `24` (co-publisher ISBN-13), and legacy type `02` after conversion to ISBN-13.

API discovery checks every unique valid ISBN while feed identity prefers primary ISBN/GTIN and falls back to a co-publisher ISBN when necessary. Series ISSN normalization remains punctuation-insensitive but ISSN is never used as a book-level duplicate key.

## API discovery separated from delivery eligibility

Google Books API discovery requires a published OMP submission, a valid detectable ISBN, and a configured API key. It does not require an enabled delivery transport, collection code, PDF/EPUB asset, price, Sales Rights entry, or remote transport credential.

Delivery eligibility remains separate. A historical title may therefore be linked to an existing Google Volume while remaining ineligible for publisher delivery for a clearly reported data reason.

## Google ONIX and content validation

ONIX contract tests verify:

- ONIX 3.0 namespace/release;
- canonical ISBN-13 `RecordReference`;
- ProductIdentifier type 15;
- title/contributor/publisher/publication-date requirements;
- no empty XML elements;
- at least one `A01` contributor role;
- free products use `UnpricedItemType 01` and no zero-price composite;
- Sales Rights include a Territory when required;
- series ISSN is canonicalized;
- proprietary collection identifiers use publisher-neutral labeling.

Delivery-manifest tests additionally verify the expected `onix/validate`, `<COLLECTION>-full`, `<COLLECTION>-rights`, and `ebooks/<COLLECTION>` path contracts and content/asset fingerprinting.

## Public OMP book page

Exact, unambiguous Google Volume IDs remain eligible for the public Google Books link. OMP 3.5.0-5 invokes `Templates::Catalog::Book::Details` after its publication-format identifiers/DOI block, and the plugin retains `Hook::SEQUENCE_CORE` so the Google Books identifier appears before the normal citation widget without a core template override.

## Dashboard, localization and publisher neutrality

The 0.1.2.x dashboard provides four primary tabs:

1. Overview;
2. Authentication;
3. Delivery and files;
4. Catalogue.

English, Spanish and Brazilian Portuguese catalogues each contain **229** Google Books locale keys with identical key sets. Distribution-source checks reject hardcoded known deployment domains, known real collection codes and plaintext secrets. Documentation/examples use generic values such as `AB12C34`.

## Repository CI hardening

The public repository now carries a permanent GitHub Actions workflow (`.github/workflows/ci.yml`) that runs `tests/run_all.sh` for pull requests and pushes to `main`. The workflow installs the PHP/SQLite/XML and Python/lxml runtime dependencies on a clean Ubuntu runner before executing the same suite used for local packaging.

## Automated test results

The final source tree passes:

| Suite | Result |
| --- | ---: |
| Core behavior, identifiers, Google matching, auth/secret and delivery contracts | 218/218 |
| Repository/database state, including delivery-file state | 42/42 |
| OMP mapper/DAO/code-24 ISBN regression | 24/24 |
| Plugin-settings migration | 16/16 |
| Package, locale, security and source contracts | 207/207 |
| OMP 3.5 compatibility smoke suite | 55/55 |
| Dashboard POST/persistence/queue smoke suite | 40/40 |
| **Total behavioral/contract assertions** | **602/602** |

In addition, all **42 PHP files** in the release are linted with `php -l`; any syntax error fails packaging.

## Distribution validation procedure

After the source tree passes the complete suite:

1. build ZIP and TAR.GZ archives with exactly one top-level `googleBooks/` directory;
2. extract each archive into a fresh directory;
3. rerun `tests/run_all.sh` independently from each extracted archive;
4. compare the extracted ZIP and TAR.GZ trees;
5. scan distributed files for known real deployment collection codes/domains and plaintext secrets;
6. calculate SHA-256 checksums for both final archives.

The final extracted-archive results are recorded in `validation-results.txt` and the release checksum file.

## Live-production boundary

Local validation proves the plugin's source contracts and simulated OMP/PKP integration, not a specific publisher's PHP extensions, outbound firewall, SFTP/FTP/GCS credentials, queue worker, remote filesystem permissions, or Google Partner Center configuration. Each selected remote transport must be tested from the production OMP installation.

Google must still perform its one-time publisher onboarding and ingestion processing. The plugin intentionally does not fabricate missing catalogue records merely to satisfy an onboarding sample count.
