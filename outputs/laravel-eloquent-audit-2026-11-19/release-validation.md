# Production release integration validation

The cleanup is integrated onto production main `396a1984`, rather than deploying the older divergent development branch. Production was verified through the Laravel Cloud application/environment/domain configuration: PickSports production, `picksports.app`, branch `main`, PHP 8.5 and Node 24. The configured build installs locked Composer/npm dependencies, builds assets and optimizes Laravel; the deploy step runs `php artisan migrate --force`. The previous successful deployment is `depl-a34457ba-f173-445c-9e79-07d58e9eba52`.

## Preserved current-production behavior

- Retained main's source-aware `NflCalibrationSample`, recorded result cutoffs, canonical lineage, streaming MLB export and external CFB snapshot-section resolver. Removed redundant cleanup-only DTOs with no consumers.
- Retained NFL prop model versions and numerical calculations from main; the older branch's pending matchup model changes are not part of this release.
- Retained official CFB availability coverage in cached evidence contexts, including absence/completeness semantics and cutoff identity.
- Retained compact dashboard chunks, cached monitoring request/report processing, historical archive readers, source-quality/completeness guards, unchanged-play no-op handling, canonical market-total behavior and fresh approval checks. C05 counts forecast row reads separately from the required approval EXISTS gate.
- Added explicit relation preparation where strict local/test loading exposed newer main consumers: MLB settlement grading game teams and NFL evidence trial snapshot/run/game/event graphs. Test-only collection assertions explicitly load their relations.

## Schema and relationship evidence

Release inventory covers 193 application models, 538 associations and 199 audited tables. The accessor-derived metadata payload key is explicitly distinguished from physical FK columns; no guessed schema column was added. The inventory baseline remains a disposable, empty SQLite database, with seeded invalid-data regressions separate from production.

A bounded read-only production preflight (Cloud command `comm-a3448b38-941b-4d79-a903-c04c6503b5a8`) found zero duplicate group join-link groups and zero unexpected Elo active-slot values. The replacement `(team_id, game_id, active_slot)` unique key and supporting team index exist. The two new November 19 cleanup migrations were not yet applied when checked. No rows were backfilled, merged or deleted by the preflight. This is a narrow deployment check, not a full production relationship audit.

Four additional migrations present only on newer main required guardrail reconciliation: three legacy-ID nullability expansions and CBB recent-form decimal precision widening. They now declare their actual expansion semantics. Decimal capacity validation rejects narrowing, changed scale and mismatched target chains; MySQL SQL-generation coverage verifies `decimal(7,3)` with unchanged nullable behavior. These historical migrations are not rerun on already migrated production.

## Local integrated checks

All PHP checks used explicit testing SQLite in memory and a disposable release worktree. A fixed test-only APP_KEY was supplied. Worktree tests requiring build provenance supplied SOURCE_VERSION because the legacy Git-file fallback assumes a normal checkout. Provider and AI calls used test fakes. Shards overlap; do not add counts as a unique application-wide total.

| Scope | Result |
| --- | --- |
| Complete NFL feature directory | 1448 passed; 8364 assertions |
| API V2, GameSummary, Sports, dashboard, public pages, settings and resources | 1,224 passed; 15,712 assertions |
| ESPN/OddsApi, exports, calibration, Elo and migration fixtures | 330 passed; 1,512 assertions |
| Relationships, delivery, CFB evidence, performance and boundaries | 220 passed; 2,689 assertions |
| Additional snapshot metadata/archive coverage | 26 passed; 121 assertions |
| Architecture, migration repair and rest-day storage | 30 passed; 92 assertions |
| Frontend Node regressions | 429 passed |
| Frontend format, ESLint, TypeScript and Vite build | Passed |
| Repository-wide Pint | Passed |

Deployment completion and post-release verification are recorded separately after the platform confirms the release. No provider refresh, forecast regeneration, calibration promotion or numerical tuning is part of deployment verification.
