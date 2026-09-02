# Google Books Integration for OMP 0.1.2.15 - Validation Report

**Target:** Open Monograph Press 3.5.x, validated against OMP/PKP 3.5.0-5 public contracts  
**Author:** Bruno Cesar Alves Marcelino  
**Organization:** Scientia International  
**Release date:** 2026-09-02

## Scope

Release 0.1.2.15 retains the transport-neutral delivery layer, encrypted API/transport secrets, strict Google ONIX validation, organized-volume `A01` compatibility, exact-ISBN public Books resolver and source-backed ONIX enrichment. It adds actionable discovery details for every published OMP submission skipped because no valid ISBN can be detected, without misclassifying that metadata omission as a Google API failure.

Validation covers plugin code, OMP-facing contracts, identifier normalization, Google Books discovery behavior, ONIX generation, delivery-manifest generation, reversible outbound-secret protection, database state, transport configuration, localization, queue integration and distribution archives. It does not claim that Google has accepted a real publisher feed or credentials; Google-side onboarding remains external.


## SFTP endpoint and connection diagnostic hardening

SFTP endpoint parsing is now transport-neutral and deterministic. Regression tests cover bare hosts, host:port, complete `sftp://` URLs, URL path-to-root extraction, dedicated-root precedence, documentation-escaped schemes, bracketed IPv6, invalid ports, non-SFTP schemes and URL userinfo rejection. Existing installations do not require a database migration because runtime configuration is normalized when loaded, and subsequent dashboard saves persist the normalized host/port/root values.

The connection test uses libcurl connection-only setup rather than requiring a directory listing, avoiding false failures on upload-only Google-provided Dropbox accounts. Safe diagnostics classify DNS, TCP refusal/connect failure, timeout, SSH authentication, host-key and remote-access failures; they record no username/password and may include only endpoint host/port, cURL code, OS errno, resolved IPs, primary IP and IP strategy. Eligible DNS/connect/timeout failures receive an explicit IPv4 retry. Actual remote write permission remains a property of the first delivery because the connection test intentionally creates no probe file.

HTTP/HTTPS pull testing now consumes the existing secret-free feed authentication diagnostic. If an external request was observed without `Authorization`/`PHP_AUTH_USER`, the dashboard reports that the web server/FastCGI/reverse-proxy/WAF path must forward the header; the plugin does not fabricate credentials that never reached PHP.

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

The primary global `volumes.list?q=isbn:` query remains authoritative when it returns an exact candidate. When that search index is delayed, the client requests Google's public `books?vid=ISBN...` bibliographic page and extracts its Volume ID only if the page's ISBN metadata row normalizes to the OMP ISBN. Regression fixtures verify successful delayed-index resolution, exact-ISBN rejection, recovery after a simulated API quota failure, and the absence of the former plain-ISBN/title list-query burst. Unresolved products and failures also produce non-empty per-submission run details.

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

Exact, unambiguous Google Volume IDs remain eligible for the public Google Books action. The public template no longer exposes the repeated ISBN or internal Volume ID. The Books action is built as `https://books.google.com/books?id=<VolumeID>` because Google's `volumeInfo.infoLink` can itself point to the Play Store. A separate Google Play Books action is rendered only when either the API or an exact-ISBN public resolver exposes a safe Play acquisition URL and confirms an e-book. OMP 3.5.0-5 invokes `Templates::Catalog::Book::Details` after its publication-format identifiers/DOI block, and the plugin retains `Hook::SEQUENCE_CORE` so the action block appears before the normal citation widget without a core template override. A scoped, versioned frontend stylesheet provides responsive buttons, keyboard focus and decorative inline vector icons.

## Dashboard, localization and publisher neutrality

The 0.1.2.x dashboard provides four primary tabs:

1. Overview;
2. Authentication;
3. Delivery and files;
4. Catalogue.

English, Spanish and Brazilian Portuguese catalogues each contain **230** Google Books locale keys with identical key sets. Distribution-source checks reject hardcoded known deployment domains, known real collection codes and plaintext secrets. Documentation/examples use generic values such as `AB12C34`.

## Repository CI hardening

The public repository now carries a permanent GitHub Actions workflow (`.github/workflows/ci.yml`) that runs `tests/run_all.sh` for pull requests and pushes to `main`. The workflow installs the PHP/SQLite/XML and Python/lxml runtime dependencies on a clean Ubuntu runner before executing the same suite used for local packaging.

## Automated test results

The final source tree passes:

| Suite | Result |
| --- | ---: |
| Core behavior, identifiers, Google matching, auth/secret and delivery contracts | 246/246 |
| Large ONIX feed | 8/8 |
| Google commercial validation profile | 22/22 |
| Strict Google Play profile | 10/10 |
| Source-backed ONIX enrichment | 9/9 |
| Repository/database state, including delivery-file state | 43/43 |
| OMP mapper/DAO/code-24 ISBN regression | 28/28 |
| Plugin-settings migration | 16/16 |
| SFTP endpoint normalization and staged diagnostic regression | 28/28 |
| Package, locale, security and source contracts | 271/271 |
| OMP 3.5 compatibility smoke suite | 55/55 |
| Dashboard POST/persistence/queue smoke suite | 42/42 |
| **Total behavioral/contract assertions** | **778/778** |

In addition, every PHP file in the release is linted with `php -l`; any syntax error fails packaging.

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

## Google Play Books contributor-role and XML-completeness corrections

Following direct Google Play Books validation feedback, every generated `Contributor` composite contains exactly one primary `ContributorRole`, and the builder defensively serializes only the primary role if legacy data still contains multiple role values. For organized volumes with no author, the mapper changes each OMP volume-editor/editor role to a single Google-facing `A01`; it does not append a second role and does not modify OMP source metadata. Mixed author/editor records preserve their original roles. The runtime validator rejects multi-role contributor composites under the Google profile.

Generated ONIX is also checked explicitly for a closing `</ONIXMessage>` and balanced `Product` opening/closing tags before it can be downloaded or delivered. The manager download path refuses to send XML after stray output has already started and retains an exact `Content-Length` header.

Pricing logic is unchanged and remains sourced from OMP: free titles emit `UnpricedItemType 01`, while positive market prices emit a `Price` composite with amount and currency. No publisher-specific price is hard-coded.
