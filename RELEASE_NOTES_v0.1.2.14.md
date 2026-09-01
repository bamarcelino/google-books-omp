# Google Books Integration for OMP 0.1.2.14

This release enriches the Google ONIX feed in response to Partner Center warnings while preserving source-backed metadata rules.

- Digital products without another ISBN-bearing format declare `EditionType DGO`.
- Contributor biographies stored in OMP are emitted as `BiographicalNote`.
- Managers can configure one validated fallback BISAC code for books that do not have their own valid BISAC.
- Book-specific BISAC metadata always takes priority; categories are never guessed from free text.
- Saving synchronization behavior advances the feed revision so the next delivery contains the changes.

Free products remain encoded with `UnpricedItemType 01` and never receive a zero-valued `Price` composite. No database migration is required.
