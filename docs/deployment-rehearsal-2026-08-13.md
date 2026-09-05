# Production database rehearsal — August 13, 2026

## Source and isolation

- Source backup: `psa08132026.dump`
- Backup SHA-256: `0374822d6cf8c6bea3d74f717fd0baf79752e451b7a331e2e88260c126b0257f`
- Backup completion timestamp: `2026-08-13 15:52:28`
- Source database named by the backup: `psa`
- Rehearsal database: `psa_rehearsal_20260813`
- The active local `psa2` database was not modified.

The backup contains 169 tables and 270 applied migrations. It restored into an empty MySQL 8.4.6 database in 370.4 seconds. The pre-migration fingerprint recorded 16,992,302 rows and no migration names unknown to the repository.

Archived fingerprints:

- `storage/app/deploy/production-source-20260813.json`
- `storage/app/deploy/production-post-migration-20260813.json`

## Migration result

All 19 pending additive migrations passed a SQL pretend run and then applied in 2.49 seconds. The migrated clone has 198 tables and 289 applied migrations. Exact before/after comparison found no row-count changes in any pre-existing table except the expected migration repository entries.

## Canonical event rehearsal

- Games scanned: 38,661
- Canonical events created: 38,655
- Provider mappings present: 44,325
- Sport games linked: 38,655
- Unsafe conflicts quarantined: 6
- Repeat dry run: zero events, mappings, or links proposed; the same six conflicts remained

The production data reuses six Odds API event IDs across distinct ESPN MLB games. Each pair has the same teams and date but different start times:

| Odds API event ID | Existing game / ESPN event / time | Conflicting game / ESPN event / time |
|---|---|---|
| `b8e341b8b9ef9ce224190d81b587f44d` | `424` / `401816308` / `2026-07-29 13:10` | `5368` / `401902545` / `2026-07-29 19:10` |
| `a3077f7c37822fb6cf05516100b899b7` | `520` / `401815472` / `2026-05-24 12:35` | `5352` / `401873649` / `2026-05-24 18:05` |
| `b58a772246d9949bd8d7bd3253d4b240` | `765` / `401814813` / `2026-04-05 13:10` | `5344` / `401867431` / `2026-04-05 16:30` |
| `e7ba5b1f238feff7771b61f0538aafed` | `1807` / `401816172` / `2026-07-19 19:20` | `5364` / `401897386` / `2026-07-19 12:35` |
| `3e0037816b1f96a2d03efc5baa5afd85` | `1974` / `401816115` / `2026-07-11 16:15` | `5362` / `401889917` / `2026-07-11 12:05` |
| `8258191f85f136e99b7e81e608485950` | `2165` / `401816219` / `2026-07-22 13:05` | `5365` / `401898714` / `2026-07-22 19:05` |

The backfill and live synchronizer now enforce one sport-detail row per canonical event. Dry runs also reserve planned identities in memory, so these conflicts are reported before writes instead of causing a unique-key crash or merging unrelated games.

## Prediction and user-bet rehearsal

- Legacy predictions scanned: 13,369
- Canonical predictions created: 13,363
- Prediction markets created: 53,452
- Missing canonical events: 6, corresponding to the quarantined MLB identities
- Prediction content conflicts: 0
- Repeat prediction dry run: zero creates or updates; 13,363 already synchronized
- User bets scanned: 1
- User bets normalized: 1
- Unrecognized user-bet references: 0

## Remaining promotion blockers

The strict prediction-lineage gate fails for all 13,363 canonical predictions:

- 13,363 lack a feature-schema reference.
- 13,363 lack a dataset-export-manifest reference.
- 13,363 lack a model-artifact reference.
- 7,781 also lack a model-run reference.
- No event or cross-lineage mismatches were found.

Before production promotion:

1. Resolve the six duplicated Odds API identities with authoritative provider/source data, then rerun both canonical backfills until their dry runs are clean.
2. Backfill or explicitly classify historical prediction provenance. Do not claim complete training lineage while the strict report is red.
3. Run the complete application and generated-SDK CI matrix.
4. Rehearse Cloud restore, service smoke tests, worker isolation, rollback, and DNS cutover with the actual Cloud resources.
5. Confirm redistribution rights for every provider field exposed by the public API.

The isolated rehearsal database is intentionally retained for conflict resolution and further release checks.

## Validation completed

- Focused identity, dual-write, prediction, and migration-safety tests: 27 passed with 127 assertions.
- Complete Laravel test suite: 1,933 passed with 16,081 assertions.
- PHP formatting, frontend formatting, linting, and TypeScript checks: passed.
- Production frontend build: passed.
- Repository whitespace/error check: passed.
