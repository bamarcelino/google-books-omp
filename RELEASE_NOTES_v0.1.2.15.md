# Google Books Integration for OMP 0.1.2.15

Release 0.1.2.15 makes published books without a detectable ISBN actionable in the Google Books dashboard.

Previously, catalogue discovery counted these records under **Published books without detectable ISBN**, but no local Google Books product existed from which the main table could show their identity. The run therefore reported a number without revealing the affected books.

After this update, **Execution details** records one line for each skipped book containing:

- the OMP submission number;
- the localized title;
- the exact reason for skipping;
- the supported ONIX List 5 publication-format identifier types: ISBN-10 (`02`), GTIN-13 (`03`), ISBN-13 (`15`) and co-publisher ISBN-13 (`24`).

Missing ISBN metadata is still not treated as a Google API failure. A discovery run with only these omissions remains `completed`, while giving the editor enough information to correct the corresponding publication formats and run discovery again.

No database migration is required. Install this release as an in-place upgrade without uninstalling the previous version.
