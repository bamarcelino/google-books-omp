## 0.1.2.5 - 2026-08-26

- Implements Google Play Books validation feedback requiring exactly one `ContributorRole` per `Contributor` composite.
- Removes the synthetic `A01` compatibility role previously appended to editor-only (`B01`) contributors; OMP's primary contributor role is now preserved without alteration.
- Defensively collapses legacy multi-role contributor data to one primary role when serializing ONIX.
- Adds explicit incomplete/truncated XML guards for a closing `ONIXMessage` and balanced `Product` composites before delivery.
- Hardens validation-file downloads against stray buffered HTTP output while preserving exact `Content-Length` delivery.
- Keeps pricing publisher-neutral and OMP-derived: free products use `UnpricedItemType 01`; paid OMP markets generate `Price` composites.
- Bumps the plugin and API User-Agent to 0.1.2.5 without a database migration.

## 0.1.2.3 - 2026-08-17

- Normalizes Google/publisher SFTP server input from bare host, host:port, complete `sftp://` URL, bracketed IPv6, and documentation-escaped `sftp\://` forms; URL userinfo is rejected so credentials cannot be accidentally persisted in the host field.
- Extracts an explicit URL port and optional URL path safely, while the dedicated remote-root field remains authoritative when both are supplied.
- Replaces mandatory SFTP directory-list testing with a non-destructive connection-setup probe suitable for Google-provided upload-only Dropbox accounts.
- Adds staged SFTP diagnostics for DNS, TCP refused/connect failure, timeout, SSH authentication, host-key verification, remote access and unsupported-runtime failures, including safe cURL code, OS errno, resolved IPs and primary IP.
- Adds an automatic IPv4 retry for DNS/connect/timeout failures and applies the same retry to seekable SFTP upload streams.
- Improves SFTP private-key interoperability by allowing the SSH backend to derive the public key from the configured private key when supported.
- Makes the HTTP/HTTPS pull Test connection action evaluate the most recent secret-free Basic-auth diagnostic, explicitly identifying when `Authorization` is stripped before reaching PHP instead of returning a generic success.
- Persists normalized SFTP host/port/root values from the dashboard and keeps the existing encrypted password/private-key storage unchanged.
- Updates the Google Books API User-Agent, documentation, package checks and regression suite for release 0.1.2.3.

## 0.1.2.2 - 2026-08-14

- Encrypts the Google Books API key at rest with a dedicated AES-256-GCM envelope (`gbapi:v1`) derived from OMP `general.app_key`, matching the hardening already used for recoverable SFTP/FTP/GCS credentials.
- Adds backward-compatible migration of pre-0.1.2.2 plaintext `googleApiKey` settings: the legacy value remains readable until encryption succeeds, then the plaintext setting is cleared.
- Adds an explicit dashboard option to clear the stored Google Books API key without ever rendering the secret back into HTML.
- Routes discovery, automatic verification and dashboard readiness checks through centralized encrypted API-key accessors rather than reading the legacy plaintext setting directly.
- Updates the Google Books API User-Agent to `GoogleBooksIntegrationForOMP/0.1.2.2`.
- Corrects 0.1.2.0 references in the README and documents the encrypted API-key behavior.
- Adds permanent GitHub Actions CI for pull requests and pushes to `main`, running the complete plugin validation suite on a clean runner.
- Extends security/package regression coverage to reject plaintext API-key persistence and validate API-key encryption round-trip/tamper handling.

# Changelog

## 0.1.2.1 - 2026-08-14

- Fixed inactive Authentication, Delivery and files, and Catalog dashboard tabs after in-place upgrades where the backend could retain an older `dashboard.js` or execute the registered asset after the document-ready event.
- Added release-version cache busting to dashboard CSS/JavaScript URLs.
- Added a direct versioned dashboard-script fallback in the dashboard template while retaining TemplateManager registration; the JavaScript bootstrap is now idempotent.
- Dashboard JavaScript now initializes both before and after `DOMContentLoaded`, covering backend asset timing differences.
- Added native radio/CSS tab switching as a no-JavaScript fallback, so all four primary dashboard tabs remain navigable even if scripting is unavailable.
- Server-renders the currently selected delivery transport panel as active so the saved transport configuration remains visible before JavaScript initializes.
- Added keyboard activation for dashboard tab labels and regression checks for cache busting, direct asset loading and CSS fallback navigation.

## 0.1.2.0 - 2026-08-14

- Added a transport-neutral Google publisher-delivery layer while preserving the existing HTTP/HTTPS pull feed as the backward-compatible default.
- Added Google-provided SFTP Dropbox, publisher-controlled SFTP, publisher FTP/FTPS, Google Cloud Storage, and protected local-export transports.
- Added a dedicated **Authentication** dashboard tab for Google Books API settings, HTTP crawler credentials, SFTP/FTP credentials, private-key authentication, and Google Cloud Storage writer credentials.
- Added a dedicated **Delivery and files** tab with transport selection, payload controls, runtime capability reporting, connection testing, immediate queued delivery, forced delivery, validation-sample controls, and the logical Google directory tree.
- Added encrypted reversible secret storage for outbound transport credentials using AES-256-GCM with a key derived from OMP `general.app_key`; incoming crawler passwords remain one-way hashed.
- Added `google_books_delivery_files` state tracking so push/staging modes skip unchanged resources by fingerprint and retain per-path delivery/error state.
- Added `DeliveryJob` integration after catalogue/submission feed synchronization so remote delivery runs in OMP's background queue.
- Google-provided SFTP Dropbox is treated as a non-destructive dropbox destination; stale remote cleanup is limited to publisher-controlled inventory destinations, GCS, and protected local export.
- Added transport capability detection for cURL/SFTP, PHP FTP/FTPS, OpenSSL/GCS, local export, and ZipArchive.
- Retained the secret-free HTTP Basic Auth diagnostic from 0.1.1.6 for publishers that continue using HTTP/HTTPS pull.
- Expanded EN/ES/PT_BR locale catalogues and regression suites for delivery configuration, encrypted secrets, migrations, dashboard actions, and package contracts.

## 0.1.1.6 - 2026-08-14

- Added a secret-free feed authentication diagnostic stored per press after authenticated feed requests, allowing managers to distinguish a stripped Authorization header from username/password mismatches without server-shell access.
- The dashboard now reports whether Authorization reached PHP, which PHP/header source exposed it, whether Basic credentials decoded, and whether the received username/password match the configured values. It never stores or displays the received username, password, Authorization value, or password hash.
- Added Authorization fallbacks for `$_ENV`, `getenv()` and `FCGI_HTTP_AUTHORIZATION` in addition to the 0.1.1.5 server/request-header paths.
- Saving feed settings clears stale authentication diagnostics so the next curl/Google request produces a fresh result.
- Successful feed authentication is recorded once without writing to the database for every subsequent crawler file request.
- Added regression coverage for diagnostic safety, environment/FastCGI header fallbacks, credential matching, and dashboard diagnostic wiring.

## 0.1.1.5 - 2026-08-14

- Hardened Google Books feed HTTP Basic Authentication for Apache, LiteSpeed, nginx and PHP-FPM/FastCGI hosting layouts after a production crawler endpoint returned the plugin's own `401` challenge despite valid generated credentials.
- Feed authentication now falls back from incomplete native `PHP_AUTH_USER`/`PHP_AUTH_PW` variables to the raw `Authorization` header instead of prematurely treating a missing native password as authoritative.
- Added case-insensitive `Authorization` discovery through `getallheaders()` and `apache_request_headers()` in addition to `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` and `AUTHORIZATION` server variables.
- Kept password storage and verification unchanged (`password_hash()` / `password_verify()`); no plaintext credential logging or URL-based credentials were introduced.
- Added regression coverage for partial FastCGI native-auth variables, request-header fallbacks and redirected Authorization headers.

## 0.1.1.4 - 2026-08-14

- Reordered the public Google Books identifier block so it appears immediately after OMP publication-format identifiers/DOI and before the Citation Style Language widget.
- Uses the supported PKP hook sequence API (`Hook::SEQUENCE_CORE`) on `Templates::Catalog::Book::Details`; the bundled Citation Style Language plugin uses the default normal sequence, so no core template override or frontend DOM manipulation is required.
- Added an OMP 3.5 regression check for the hook sequence.
- Reviewed the generated ONIX 3.0 validation sample against Google's current ONIX guidance; no structural change to the validation profile was required in this release.

## 0.1.1.3 - 2026-08-14

- Fixed the HTTP 500 affecting Google Books dashboard actions on OMP 3.5.0-5. The 0.1.1.x job classes redeclared the inherited `BaseJob::$tries` property as `public int`, but OMP 3.5.0-5 defines it as an untyped public property; PHP property invariance makes that class declaration fatal when the job is autoloaded.
- Restored OMP-compatible job declarations by keeping `$tries` untyped while preserving the configured retry counts.
- Added an OMP 3.5.0-5 inheritance-compatibility regression test that mirrors the exact `BaseJob` property types from the PKP release used by OMP 3.5.0-5, so this fatal can no longer pass the plugin test suite unnoticed.
- Added package checks that reject typed `$tries` overrides in Google Books jobs.
- Kept the 0.1.1.2 ISBN/ONIX/discovery fixes unchanged.

## 0.1.1.2 - 2026-08-14

- Fixed OMP 3.5 ISBN discovery to load publication formats from `PublicationFormatDAO::getByPublicationId()` instead of relying on a non-guaranteed `publicationFormats` property on `Publication`.
- Added ONIX List 5 code `24` (co-publisher ISBN-13) to ISBN normalization and Google Books discovery, with primary ISBN-13/GTIN identifiers still preferred for feed identity.
- Discovery now checks every valid ISBN carried by a publication format, so a co-publisher ISBN can be linked even when another ISBN is also present.
- Rebuilt EN/ES/PT_BR gettext files with valid PO headers and correctly separated entries, fixing raw locale keys with `uniqid()`-style suffixes in the dashboard.
- Reduced catalogue API batches from 25 to 10 books and added conservative pacing between requests.
- Increased bounded retry/backoff for transient Google Books API 429/5xx responses.
- Manual dashboard jobs are forced away from a synchronous queue connection and delayed briefly, preventing large API/catalogue work from executing inside the POST request and returning HTTP 500.
- All plugin jobs now inherit a queue-safe base class that falls back to OMP's database queue when the installation is configured with Laravel's synchronous queue; chained catalogue batches preserve that background connection and are paced between batches.
- Split the dashboard history into latest API discovery and latest feed synchronization so old feed eligibility messages are not presented as discovery results.
- Added regression tests for DAO-backed publication formats, code-24 ISBNs, PO structure/template key coverage, background queue isolation and the smaller discovery batch.

## 0.1.1.1 - 2026-08-14

- Fixes HTTP 500 on the dashboard after upgrading from 0.1.0.x to 0.1.1.0.
- Adds the missing `upgrade.xml` descriptor so OMP executes database migrations during in-place plugin upgrades.
- Adds an idempotent dashboard schema preflight that repairs installations already affected by the 0.1.1.0 upgrade omission before repository queries are executed.
- Adds regression checks for the plugin upgrade descriptor and the 0.1.0.x -> 0.1.1.x schema transition.

## 0.1.0.6 - 2026-08-14

- Fixed OMP 3.5 backend button styling by using the native `pkp_button` / `pkp_button_primary` classes instead of the invalid `pkpButton` class.
- Reworked the dashboard layout with scoped plugin CSS for cards, status metrics, settings grids, responsive tables and clearly differentiated actions.
- Hardened Google collection-code normalization against Unicode/zero-width whitespace introduced by copy/paste.
- Kept the official requirement of exactly seven alphanumeric collection-code characters; invalid attempts now return the normalized value and detected character count to the dashboard instead of clearing the field.
- Added regression coverage for the generic Partner Center-style code `AB12C34`.

## 0.1.0.5 - 2026-08-14

- Fixed Save, catalog actions and ONIX validation POSTs on multilingual OMP 3.5 presses.
- Removed locale suppression from dashboard/action URLs so OMP no longer redirects POST requests and discards their form body/CSRF token.
- Dashboard redirects now preserve the active UI locale.
- Google crawler feed URLs use the press primary locale explicitly, avoiding canonical-locale redirects before Basic Auth and content delivery.
- Added regression coverage for OMP 3.5 multilingual canonicalization, localized POST targets and primary-locale feed URLs.

## 0.1.0.3 - 2026-08-13

- Finalized the OMP 3.5 dashboard 404 repair after reproducing the enabled-plugin resolution path in `Dispatcher`, `VersionDAO` and `PluginSettingsDAO`.
- Uses `googlebooksplugin` as the canonical runtime/settings key and keeps the installation product/directory as `googleBooks`.
- Migrates legacy `googlebooks` settings transactionally, preserves active/canonical values, supports site-level rows and invalidates both canonical and legacy OMP settings caches.
- Keeps the repair release non-lazy so it can execute on installations whose old enabled row cannot satisfy OMP 3.5 lazy loading.
- Uses canonical lowercase routes `googlebooks` and `googlebooksfeed`, while accepting prior camel-case and locale-prefixed URLs.
- Revalidates ZIP and TAR.GZ after independent extraction and execution of the complete test suite.

## 0.1.0.2 - 2026-08-13

- Fixed the persistent OMP 3.5 dashboard 404 caused by the plugin runtime-name mismatch.
- The plugin now uses `googlebooksplugin`, the lowercase `GoogleBooksPlugin` class name expected by OMP 3.5 when resolving enabled plugin settings.
- Added an idempotent transactional migration from the legacy `googlebooks` key used by releases 0.1.0.0 and 0.1.0.1.
- The migration preserves canonical settings, merges the enabled flag so an active installation remains active, supports context and site-level rows, and invalidates OMP plugin-setting caches after direct database changes.
- Declared this repair release as non-lazy so OMP can load and execute the migration even when the legacy enabled row cannot pass the OMP 3.5 lazy-load join.
- Canonicalized dashboard and feed page routes to lowercase `googlebooks` and `googlebooksfeed`.
- Kept case-insensitive compatibility for previously generated `/googleBooks` and `/googleBooksFeed` URLs, including localized forms such as `/press/pt_BR/googleBooks`.
- Kept all dashboard and crawler links on explicit `Application::ROUTE_PAGE` URLs, avoiding the plugin-grid component-router placeholder.
- Added regression tests for the OMP 3.5 enabled-plugin query, legacy setting migration, cache invalidation, localized/camel-case route handling, page-handler registration, and archive extraction.

## 0.1.0.1 - 2026-08-13

- Fixed the Google Books dashboard action URL when opened from OMP's plugin grid.
- The action uses an explicit `Application::ROUTE_PAGE` dispatcher URL, preventing the component-router placeholder `$$$call$$$/goog/fetch-grid` from leaking into the public link.
- This release did not yet correct the OMP 3.5 plugin runtime-name mismatch and could therefore still return 404 on normal page requests.

## 0.1.0.0 - 2026-08-13

Initial publisher-neutral release candidate for OMP 3.5.x.

- Google Books exact discovery by normalized ISBN.
- ISBN-10 / ISBN-13 equivalence plus label- and punctuation-insensitive comparison.
- Series ISSN label, punctuation and checksum normalization.
- Detection of multiple exact Google Volume IDs for one normalized ISBN.
- Google-specific ONIX 3.0 full and rights feeds.
- Initial ONIX validation sample endpoint.
- Authenticated virtual HTTP feed for ONIX, whole-book PDF/EPUB and JPEG/PNG cover assets.
- Incremental and forced catalog synchronization.
- Per-book synchronization and refresh.
- API-only catalog verification.
- Bounded automatic post-crawl verification jobs.
- OMP public Google Books identifier/link hook.
- Sales-rights and paid/free price validation.
- Publisher-neutral settings and imprint-to-collection-code mapping.
- Database uniqueness on canonical press/ISBN pair.
- Deterministic ONIX `SentDateTime` tied to a persisted feed revision.
- Automatic refresh hooks for published proof-file additions, edits and removals.
- Local retirement of replaced ISBN products and unpublished monographs.
- Safe public Google Books fallback URL generated from an unambiguous Volume ID.
- SQLite repository smoke tests, mapper fixtures and OMP 3.5 compatibility smoke tests.
