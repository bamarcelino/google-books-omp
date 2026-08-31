# Google Books Integration for OMP 0.1.2.10

Release date: 2026-09-01

## Organized-volume Google Play compatibility

- When an OMP publication has no `A01` author, every volume editor/editor is emitted as `A01` in the Google-facing ONIX model.
- When a real `A01` author exists, editor and organizer roles remain unchanged.
- The fallback changes neither the contributor groups nor any other metadata stored in OMP.
- Every generated `Contributor` continues to contain exactly one `ContributorRole`.
- Translators and other non-organizer roles are never promoted.

## Validation

Regression coverage includes one organizer, multiple organizers, mixed author/editor records and non-organizer-only records. The complete source and archive validation suites must pass before the automated GitHub release is published.

## Compatibility

- Open Monograph Press 3.5.x
- Primary target: OMP 3.5.0-5 LTS
- No database migration required
- Existing credentials, delivery settings, collection codes and synchronization history are preserved
