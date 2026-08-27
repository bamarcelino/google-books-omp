# Google Books Integration for OMP 0.1.2.8

This maintenance release enriches Google ONIX with optional metadata requested during Google Play Books onboarding while preserving the rule that publisher metadata must never be fabricated.

## Enrichment

- Subject: explicit BISAC/Thema codes are exported only from explicit source fields; OMP keywords, subjects and disciplines are exported as ONIX keyword subjects.
- Extent: explicit total page count and OMP front/back matter values are exported when present. The plugin does not guess PDF pagination.
- RelatedProduct: other real ISBN-bearing publication formats of the same OMP monograph are linked as alternative formats.
- Summary: an existing localized abstract can be used as a fallback when the publication-locale abstract is empty.

All previously validated commercial structures, free-of-charge handling, stable validation URL, complete-document guards and Google crawler transport behavior remain unchanged.
