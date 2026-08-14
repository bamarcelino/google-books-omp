# Google Books Integration for OMP


### 0.1.2.2 security hardening + multi-transport delivery

This release adds a transport-neutral Google delivery layer and a dedicated dashboard authentication workspace. The plugin can keep Google's HTTP/HTTPS pull model, push to a Google-provided SFTP Dropbox, push to a publisher-controlled SFTP or FTP/FTPS server, stage the same Google directory tree in Google Cloud Storage, or write a protected local export as an operational fallback. Outbound credentials are encrypted reversibly with the OMP application key; the incoming HTTP crawler password remains one-way hashed. Incremental delivery state prevents unchanged files from being retransmitted unnecessarily. Google Books API discovery remains independent from publisher delivery.

Publisher-neutral Google Books / Google Play Books synchronization for **Open Monograph Press 3.5.x**.

- Author: **Bruno Cesar Alves Marcelino**
- Organization: **Scientia International**
- License: **GNU GPL v3 or later**
- Installation directory/product: `googleBooks`
- Canonical OMP runtime/settings key: `googlebooksplugin`
- Current release: `0.1.2.2`
- Primary compatibility target: **OMP 3.5.0-5 LTS**

## What the plugin does

The plugin connects an OMP press to Google's official **Automated Content Fetching** workflow. OMP remains the publisher's source of truth.

It provides:

- exact Google Books discovery by normalized ISBN;
- ISBN-10 / ISBN-13 equivalence and punctuation-insensitive matching;
- series ISSN normalization, including hyphens, dots, spaces and `X` check digits;
- protection against duplicate normalized ISBNs inside the same OMP press;
- detection of multiple exact Google Volume IDs using the same normalized ISBN, without automatically choosing an ambiguous public link;
- a Google-specific ONIX 3.0 full metadata feed;
- a Google-specific ONIX 3.0 rights/sales feed;
- PDF, EPUB and JPEG/PNG cover delivery from existing OMP files through HTTP/HTTPS pull, Google-provided SFTP Dropbox, publisher SFTP, FTP/FTPS, Google Cloud Storage, or a protected local export;
- initial ONIX validation sample generation from a real published OMP book;
- incremental synchronization and force-refresh actions, with per-transport file fingerprints and delivery state;
- API-only status verification that does not modify the feed;
- bounded automatic post-crawl Google Books rechecks for newly exposed books;
- storage of Google Volume ID, information link and preview link;
- a public Google Books link on the OMP book page through the OMP template hook, without core modifications;
- per-book and whole-catalog synchronization controls;
- synchronization run history and error status;
- a four-tab dashboard for overview, Google/API and transport authentication, delivery/files, and catalogue operations;
- connection testing and queued delivery controls for push/staging transports.

## Important distinction: ISBN and ISSN

Google Books identifies individual book products by **ISBN**. Therefore duplicate prevention and Google discovery use a canonical ISBN value.

The plugin removes common `ISBN`, `ISBN-10` and `ISBN-13` labels and punctuation before comparison, validates the checksum, converts ISBN-10 and ISBN-13 equivalents when possible, and stores the preferred canonical ISBN-13. For example, all of the following identify the same book product:

```text
978-0-306-40615-7
978.0.306.40615.7
978 0 306 40615 7
9780306406157
0-306-40615-2
```

**ISSN is not used to decide that two individual books are the same book.** ISSN identifies a serial/series. When OMP provides a series ISSN, the plugin canonicalizes it before ONIX output. For example:

```text
2049-3630
2049.3630
2049 3630
20493630
```

all become `20493630` internally and are emitted as the same ONIX collection identifier. Prefixes such as `ISSN:` and `ISSN-L:` are also removed before normalization. This avoids punctuation differences in series metadata without incorrectly using an ISSN as a book-level deduplication key.

## Google collection code

Google Play Books Partner Center currently documents the collection code as exactly **seven alphanumeric characters**. The value is displayed under **Book Catalog > Advanced > Manage templates**. A code such as `AB12C34` is seven characters (`A` `B` `1` `2` `C` `3` `4`).

The plugin keeps this seven-character requirement. It does not accept six-character codes because Google uses the collection code in ONIX and automated-fetch directory names. To avoid false validation failures when a code is copied from a web interface, the plugin removes normal Unicode whitespace and zero-width spacing before validation and uppercases the value. Any other unexpected character is rejected instead of being silently altered.

When validation fails, the normalized attempted code and detected character count are returned to the dashboard so the field no longer clears without explanation.

## Google architecture

The public Google Books API does not provide a publisher endpoint that uploads a new book. The plugin therefore separates discovery from delivery:

1. **Google Books API** - exact discovery and later verification by ISBN.
2. **Google publisher delivery** - exposes or transfers ONIX, EPUB, PDF and cover assets using the transport selected for the press.

Release 0.1.2.2 supports every server/transport choice exposed by Google's automated-content onboarding form, plus a protected local staging fallback:

- **HTTP/HTTPS pull** - Google fetches the authenticated virtual OMP feed;
- **Google-provided SFTP Dropbox** - OMP pushes files to the SFTP dropbox credentials supplied by Google;
- **publisher SFTP** - OMP pushes to a publisher-controlled SFTP server that Google can fetch from;
- **publisher FTP/FTPS** - OMP pushes to a publisher-controlled FTP or TLS-enabled FTPS server;
- **Google Cloud Storage** - OMP stages files in a configured bucket using a publisher writer service account, while Google can be granted reader access;
- **protected local export** - OMP materializes the delivery tree under its protected files directory for manual/operational transfer when a remote transport is unavailable.

All transport modes use the same logical Google tree:

```text
onix/
    validate/
    <COLLECTION>-full/
    <COLLECTION>-rights/

ebooks/
    <COLLECTION>/
```

For HTTP/HTTPS pull, that tree is exposed through `/googlebooksfeed/`. For push/staging transports, the plugin writes the same relative paths to the remote destination. Google-provided SFTP Dropbox is treated as a dropbox/event destination, so the plugin does not delete prior remote uploads during local stale-file cleanup.

The plugin's canonical metadata payload is ONIX 3.0. Google also documents CSV metadata as an alternative ingestion format, but this plugin does not generate a parallel CSV feed because ONIX already carries the supported bibliographic and rights model used by the integration.

## Synchronization model

Discovery does not suppress updates.

If a book already exists in Google Books, the plugin stores its Google Volume ID and **still keeps the current OMP representation eligible for the publisher feed**. This lets Google reconcile newer metadata/content with an older Google record.

The normal flow is:

```text
Published OMP book
    -> normalize and validate ISBN
    -> query Google Books API
    -> link an exact existing Volume ID when unambiguous
    -> validate OMP metadata, rights and files
    -> prepare current ONIX/content delivery state
    -> expose via HTTP/HTTPS pull OR push/stage through the selected transport
    -> Google processes new/modified resources
    -> plugin rechecks Google Books
    -> Google Volume ID/link appears in OMP
```

### Discover catalog in Google Books

Queries the public Google Books API for every valid ISBN carried by the current OMP publication formats. Discovery does not require an active feed, collection code, PDF/EPUB proof, prices or Sales Rights. Exact unambiguous matches are stored immediately and can appear on the public OMP book page.

Large catalogues are processed in small queued batches so the dashboard request does not remain open while external API calls are made. HTTP 429 and transient 5xx responses are retried with bounded exponential backoff and remain API errors if all attempts fail; they are not converted into “not found”.

### Synchronize publisher feed

Validates current feed-eligible products, compares hashes and prepares ONIX/content state for the selected Google delivery transport. For push/staging modes, a background delivery job uploads only new or changed resources according to the per-transport manifest state. Discovery and feed eligibility are tracked separately.

### Force full catalog refresh

Recalculates the catalogue and advances distribution state even when source hashes are unchanged. For push/staging transports, the editor can also request a forced delivery so current resources are retransmitted without modifying the original OMP PDF/EPUB files.

### Automatic post-crawl verification

When a newly synchronized book is not yet found by the Google Books API, the plugin can schedule bounded follow-up checks after 6, 24, 72 and 168 hours. These intervals are plugin policy, not a guarantee of Google's processing time.

### Local retirement and Google withdrawal

When a monograph is unpublished, a current publication version replaces an older ISBN product, or a product no longer meets the OMP feed requirements, the plugin marks the old local record as `retired`. Retired records are removed from the ONIX/content feed and are not shown on the public book page, while their previous Google Volume ID is retained for audit and possible reactivation.

This first release does **not** send a Google withdrawal/deletion instruction. Removing a product from the plugin feed is not presented as proof that Google has removed the public Google Books record. A publisher that needs an explicit Google-side withdrawal must complete that action in the Google Play Books Partner Center or through Google publisher support.

## Existing Google records and duplicates

The plugin never assumes that “not found” based on formatting means “new”. It compares canonical identifiers.

An exact Google result must contain an ISBN that normalizes to an ISBN equivalent of the OMP product. Title-only search results are never used for automatic identity decisions.

If the Books API exposes more than one distinct Google Volume ID with the same exact normalized ISBN, the plugin records `multiple_matches` and withholds automatic public linking instead of arbitrarily choosing a volume. The publisher feed can still remain available so Google can reconcile its catalog.

Inside OMP, `(context_id, isbn13)` is unique. Two OMP submissions in the same press cannot silently publish the same normalized ISBN into the plugin feed.

## Google ONIX profile

The plugin uses ONIX 3.0 and generates Google-specific records rather than using the generic OMP ONIX output unchanged.

Key rules implemented in this release include:

- stable `RecordReference` based on canonical ISBN-13;
- `ProductIDType` 15 for ISBN-13;
- required title, contributors, publisher and publication date;
- precise UTC `SentDateTime`;
- no empty XML tags;
- ONIX series identifier type `02` for a valid normalized ISSN;
- sales-right territories from OMP;
- Google-supported paid `PriceType` values only;
- free products use `UnpricedItemType` `01`, not a zero `PriceAmount`;
- full feed is metadata-only;
- rights feed carries rights/supply data and is the feed Google can use to create Partner Center records.

## Sales rights safety

The plugin does not silently claim worldwide rights.

By default, a book must have OMP Sales Rights data before it can enter the Google rights/content feed. A publisher may explicitly enable:

> Assume worldwide non-exclusive sales rights for free titles when OMP has no Sales Rights entry

This option is disabled by default and must only be enabled when the publisher actually holds those rights.

## OMP 3.5 dashboard integration

The dashboard uses OMP 3.5's native backend form/button vocabulary (`pkp_form`, `section`, `pkp_button`, and `pkp_button_primary`) and adds a scoped `styles/dashboard.css` stylesheet for the Google Books-specific layout. The stylesheet is registered only in the backend Google Books page through `TemplateManager::addStyleSheet()`.

## First-time Google setup

1. Install and enable the plugin in OMP.
2. Open **Google Books** from the plugin action.
3. In **Authentication**, configure a Google Books API key if API discovery is desired. Partner ID is optional.
4. Run **Discover catalog in Google Books**. Existing exact ISBN matches are stored independently from publisher delivery.
5. In **Delivery and files**, enter the Google collection code and select the transport required by the Google onboarding form. Multiple imprint-to-collection-code mappings are supported.
6. Select which payloads are enabled: ONIX bibliographic (`-full`), ONIX rights (`-rights`), EPUB/PDF/covers, and the validation sample.
7. Configure transport authentication in **Authentication**:
   - HTTP/HTTPS pull: crawler username and password;
   - Google-provided or publisher SFTP: host, port, username and password or private key after those values are supplied;
   - FTP/FTPS: username/password in the remote-authentication area;
   - Google Cloud Storage: publisher writer service-account JSON plus bucket/prefix configuration.
8. Use **Test connection** before enabling a push/staging transport.
9. Generate the initial ONIX validation sample from a real published OMP book when Google requests it.
10. Enable delivery. HTTP/HTTPS remains available for Google to pull; other modes can be sent with **Deliver now** and continue through queued catalogue synchronization.
11. Use **Force delivery** only when the current resources must be retransmitted even though their fingerprints have not changed.

For a **Google-provided SFTP Dropbox**, select that mode first and leave Google-supplied connection fields empty until Google sends the SFTP credentials. Once received, store them under **Authentication**, test the connection, and then run delivery.

Google must still configure/approve the publisher ingestion for the collection code. The plugin cannot bypass Google-side onboarding.

## OMP data requirements

A product is eligible only when it has, at minimum:

- a published OMP monograph;
- an approved publication format;
- a valid ISBN that can be normalized to ISBN-13;
- at least one viewable production proof in PDF or EPUB;
- title;
- publisher;
- publication date;
- at least one ONIX `A01` contributor role, as required by the Google profile;
- valid rights/supply data for the rights feed.

For paid products, OMP must also provide a positive market price, a three-letter currency code, a Google-supported ONIX PriceType, and a market territory.

## File handling

The source of truth remains the existing OMP files. Chapter-associated proofs are excluded, so only whole-book PDF/EPUB assets are eligible for a Google product. The delivery manifest can stream those files directly or copy them to the selected transport without modifying the source publication.

Google-facing filenames are normalized to the ISBN without punctuation, for example:

```text
9781234567890.epub
9781234567890.pdf
9781234567890_frontcover.jpg
```

Separate OMP cover delivery supports JPEG/JPG and PNG. Unsupported cover formats are omitted from the separate cover asset rather than mislabeled.

For push/staging transports, the plugin stores a per-transport fingerprint and delivery status for each relative path. Unchanged resources are skipped by default; failed resources are retriable; a forced delivery bypasses the unchanged-file optimization. Publisher-controlled inventory destinations can also remove stale paths. Google-provided SFTP Dropbox intentionally does not use destructive remote cleanup.

The protected local-export fallback is written below OMP's configured private `files_dir`, not to a public web directory.

## Security

- HTTP/HTTPS publisher-feed routes require HTTP Basic authentication;
- the incoming crawler password is stored as a one-way `password_hash()` value;
- outbound SFTP/FTP/GCS secrets must be recoverable to open remote connections, so they are encrypted with AES-256-GCM using a key derived from OMP `general.app_key`;
- private keys and passwords are never rendered back into the dashboard after storage; the UI reports only whether a secret is present;
- the HTTP authentication diagnostic stores only booleans, technical source labels and a timestamp; it never stores or displays the received username, password, Authorization value or password hash;
- connection/delivery diagnostics redact Authorization/Bearer material and URL userinfo before persistence;
- Google Cloud Storage writer credentials remain encrypted at rest; the optional Google reader service-account identity is stored as non-secret configuration;
- the Google Books API key is encrypted at rest with its own AES-256-GCM envelope derived from OMP `general.app_key`, is never rendered back into the settings form after storage, and pre-0.1.2.2 plaintext values are migrated opportunistically after a successful read;
- Google Books API discovery/verification is only attempted when an API key is configured; delivery generation remains independent of API availability;
- a later public API `not found` or ambiguous response never erases a previously linked Google Volume ID;
- HTTP feed pages send `X-Robots-Tag: noindex, nofollow, noarchive`;
- dashboard write actions require CSRF validation and manager/site-administrator roles;
- public Google links are escaped and use `rel="noopener noreferrer"`.

Changing OMP `general.app_key` after transport secrets have been stored makes those reversible secrets unreadable; re-enter the affected transport credentials after such a key rotation.

## Queue processing

Synchronization, verification, and push/staging delivery operations use the OMP/PKP job queue. OMP 3.5 can process jobs with the built-in job runner, cron, or a queue worker. A production installation must have one functioning job-processing method. HTTP/HTTPS pull does not enqueue file uploads because Google retrieves those resources directly.

## Continuous integration

The public repository includes `.github/workflows/ci.yml`. Pull requests and pushes to `main` run the complete `tests/run_all.sh` suite on a clean Ubuntu runner with the required PHP/SQLite/XML and Python/lxml dependencies. This complements, rather than replaces, production testing against a real OMP installation and Google publisher credentials.

## Installation package

The release archive must contain a single top-level directory named `googleBooks`:

```text
googleBooks/
    GoogleBooksPlugin.php
    version.xml
    classes/
    locale/
    templates/
    ...
```

Install it through OMP's plugin upload mechanism or place it under:

```text
plugins/generic/googleBooks
```

Then install/enable it in the OMP plugin manager so the database migration is applied.

## Upgrade from earlier releases

Do not uninstall an older release before updating. Upload 0.1.2.2 as an in-place plugin upgrade so existing Google Books state, discovery links, feed settings, diagnostics and run history are preserved. The idempotent schema migration adds the delivery-file state table required for incremental multi-transport synchronization.

Existing 0.1.1.x installations default to **HTTP/HTTPS pull**, preserving prior behavior until a manager explicitly selects a different transport. Existing HTTP crawler username/password settings remain valid. Outbound transport secrets introduced in 0.1.2.0 are stored separately and encrypted.

After upgrading, clear OMP data/template caches. Then open the new **Authentication** and **Delivery and files** tabs, select the desired transport, save its non-secret settings, store credentials, and use **Test connection** where applicable.

## Validation

See [`VALIDATION.md`](VALIDATION.md) for the exact local checks executed for this release.

The release is tested for code syntax, identifier normalization, exact Google matching logic, ONIX generation contracts, XML well-formedness, locale consistency, publisher-neutral runtime configuration and packaging. A live Google ingestion cannot be certified before the publisher completes Google's one-time ONIX validation and Google enables the crawler for the real collection code.

## Official references

- Google automated content fetching: https://support.google.com/books/partner/answer/2763162
- Google ONIX 3.0 / 3.1 profile: https://support.google.com/books/partner/answer/6374180
- Google ONIX troubleshooting: https://support.google.com/books/partner/answer/6373538
- Google Books API usage: https://developers.google.com/books/docs/v1/using
- Google Books Volumes list API: https://developers.google.com/books/docs/v1/reference/volumes/list
- PKP OMP source: https://github.com/pkp/omp
- PKP plugin developer guide: https://docs.pkp.sfu.ca/dev/plugin-guide/en/
