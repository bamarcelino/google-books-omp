# Google Books Integration for OMP 0.1.2.9

This maintenance release aligns the generated ONIX more strictly with the current Google Play Books ingestion profile after live CLAEC validation.

## Google Play profile corrections

- Requires at least one real `A01` author on every Google-eligible product. Editor-only (`B01`) records are no longer treated as Google Play eligible.
- Requires every emitted `Subject` to use a Google-supported `SubjectSchemeIdentifier` and to contain `SubjectCode`.
- Stops converting ordinary OMP keywords, subjects and disciplines into heading-only ONIX scheme `20` subjects.
- Stops exporting Thema scheme `93` in the strict Google feed profile.
- Keeps explicit valid BISAC codes (`SubjectSchemeIdentifier 10`) when the publisher has stored them.
- Preserves source-backed page extents, related-format ISBNs and summaries when real OMP metadata exists.
- Preserves the validated commercial profile: `SalesRights`, `ProductSupply`, WORLD markets and `UnpricedItemType 01` for free books.
- Adds model-level and final XML-boundary checks so a missing A01 or invalid Subject cannot be delivered silently.

No database migration is required.
