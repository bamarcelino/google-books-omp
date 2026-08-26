# Google Books Integration for OMP 0.1.2.5

Release date: 2026-08-26

## Google Play Books validation corrections

This maintenance release implements direct validation feedback from the Google Play Books team.

### One ContributorRole per Contributor

Generated ONIX now contains exactly one primary `ContributorRole` in each `Contributor` composite. OMP contributor roles such as `B01` (editor) are preserved as recorded. The former editor-only fallback that appended an additional `A01` role has been removed. Legacy multi-role data is defensively serialized using the primary role only.

### Complete XML delivery

The validator now rejects XML without a closing `ONIXMessage` and rejects mismatched `Product` opening/closing composites before delivery. Manager downloads also refuse to begin after stray HTTP output has already been emitted and continue to declare the exact `Content-Length` of the generated XML.

### Pricing confirmation behavior

No publisher-specific price is hard-coded. Pricing remains sourced from OMP. Products resolved as free emit `UnpricedItemType 01`; products with a positive OMP market price emit a `Price` composite with price type, amount, currency and territory.

## Compatibility

- Open Monograph Press 3.5.x
- Primary target: OMP 3.5.0-5 LTS
- No database migration required
- Existing credentials, delivery settings, collection codes and synchronization state are preserved
