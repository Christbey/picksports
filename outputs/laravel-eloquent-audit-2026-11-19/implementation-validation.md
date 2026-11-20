# Cleanup implementation validation

C01–C45 are addressed locally through implementation or a documented retained disposition. The README is the completion tracker. No commit, production deployment, production data check or production migration execution is implied.

## Test environment and results

PHP checks used PHP 8.5.2 with explicit APP_ENV=testing, empty DB_URL, DB_CONNECTION=sqlite and DB_DATABASE=:memory:. There was no cached configuration redirecting tests to another database. Schema verification used a newly migrated disposable SQLite database. Tests use their provider fakes and local fixtures; no production/provider refresh command was run.

These final runs overlap and must not be added into one claimed unique test total.

| Check | Result |
| --- | --- |
| Complete NFL Feature directory | 1,144 passed, 6,679 assertions |
| Complete API V2 directory after capability guard | 460 passed, 10,089 assertions |
| Broader HTTP/Sports/dashboard/settings/resource suite | 886 passed, 13,641 assertions |
| Additional auth/admin/nonV2 API coverage | 243 passed, 1,839 assertions |
| Platform/infrastructure/subscription/database coverage | 50 passed, 323 assertions |
| MLB/NBA/WCBB/WNBA/betting and root boundary integration | 502 passed, 3,064 assertions |
| Complete ESPN and OddsApi directories | 254 passed, 1,103 assertions |
| Additional atomic player/play/team-stat regressions | 98 passed, 367 assertions |
| AI/Application/Alerts/CBB/Scheduling/ML/MachineLearning/Unit | 375 passed, 4,402 assertions |
| Top-level Feature files in two shards | 134 passed, 1,897 assertions; 82 passed, 587 assertions |
| Relationship/calibration/export/historical forecast/identity/UserBet/BetTracker | 106 passed, 1,788 assertions |
| Explicit research availability propagation and cleanup | 124 passed, 474 assertions |
| Final root boundary and cached configuration/process environment tests | 11 passed, 34 assertions |
| Targeted frontend behavior tests for pregame/public formatting | 6 passed |
| Other passed shards | Actions 107; Predictions 54; Notifications 77; Validation 107; Listeners 2; TeamLogos 2; Events 21; ProviderData 17; Services 5 |
| Repository-wide Pint --test | Passed after formatting the final root test additions |
| OpenAPI generation and byte comparison with docs/openapi-v2.json | Passed |
| git diff --check | Passed |

The first monolithic test attempt failed while PHPUnit recursively exported a scheduling Event object on assertion failure and exhausted its memory limit. The assertion now reports scalar values. Final validation used isolated shards to avoid accumulating unrelated framework/mock state; the affected scheduling/validation checks pass. This is not a claim that a final monolithic all-suite invocation passed.

## Migration guardrail repairs

All six previously reported violations are resolved locally. Three migrations explicitly document nullability expansions. Model-version strings and college basketball rest days use narrowly validated capacity-expansion declarations; guard regressions reject narrowing, mismatched types, unsigned conversions, changed defaults and mixed destructive operations. CFB Elo retains legacy indexes during expansion and retires them in a separate preflighted contract migration after version-aware code is deployed. See [CFB Elo release sequencing](../../docs/cfb-elo.md#schema-release-sequence).

The migration/rest-day/Elo regressions, including MySQL SQL-generation checks for all six model-version columns, pass: 26 tests, 140 assertions. The combined architecture and focused regression run passes: 42 tests, 158 assertions (including all 16 architecture tests). Repository-wide Pint and `git diff --check` pass. Production/MySQL execution remains unverified; local MySQL grammar assertions do not certify production schema or data. No production migration was run.

## Important implementation and retained decisions

C01 covers the listed shared/sport player-stat, play and team-stat replacements, not every ingestion write in the application. It stages validation before game-locked transactional replacement, preserves invalid/incomplete prior data and event-aware writes, batches identities and retains public processed-count semantics.

C21 was independently reviewed after the initial refactor. That review found a downstream prop consumer still reading the removed synthetic research attribute. It now receives explicit verified facts through both calculation paths. Regressions cover time/source/team precedence, consecutive batch isolation, exceptions, model dirtiness and non-persisting previews.

C32 moved 34 validation operations to Form Requests. Eleven inline operations are intentionally retained because ownership, disabled-feature/sport gates, dynamic reference creation or game-dependent rules must precede validation. Their exact locations and rationale remain in the HTTP report. C37 retains pure query-free EPA arithmetic, stored-summary explanation formatting and controller-batch-primed MLB period context; those are not database work during serialization on the supported paths.

C35 retains compatible explicit-model forecast adapters for standalone/legacy callers and genuine dynamic sport factories. Normal MLB orchestration already supplies batch-normalized DTOs. Channel formatting normalizes once per operation and does not cache across queued sends; fresh send-time validation, locks and deduplication remain intentional. Scoped CFB evidence is injected into prop analysis without replacing the statistical calculation with an enormous unrelated dependency constructor.

C40 measured a ten-game local synthetic export fixture: accepted-source/affiliation reads fell from 30 to 2 using bounded game-chunk preparation. Measured peak PHP process memory remained 87.5 MiB, including fixture/framework overhead. Large per-game payload reads remain deliberate to preserve exact point-in-time source/contract decisions and bound payload memory. This is not a production latency claim.

## Relationship review boundary

The generated inventory covers 188 application models, 526 associations, seven package-owned targets and 194 audited local tables. It records keys/types/cardinality/nullability/indexes/deletion rules and domain producer/consumer/loading/history policies. The zero-row migrated baseline is labeled as such; seeded invalid fixtures verify orphan, duplicate and mismatched/dynamic-reference detection. Neither artifact certifies production records.

Corrections include useful ownership relations, current Passport owner keys/type, allowlisted polymorphic forecast/game references, sport/model identity checks, and the missing group join-link uniqueness constraint. Historical readers retain recorded stat team ownership and frozen snapshot/revision identity. The legacy basketball boxscore writer can still update mutable Player.team_id during historical imports; C45 explicitly retains that existing input policy and does not claim a new point-in-time roster writer.

Existing unrelated working-tree edits were preserved. All new/changed cleanup PHP files were formatted and relevant behavior checked. No provider refresh, production queue execution, numerical model tuning or production deployment was required for this work.

## Independent final review

The subsequent [multi-agent final review](final-review.md) reconciles C01–C45 with implementation and retained dispositions. It repaired missing team hydration for a shared prior MLB matchup in two recent-game lists, strengthened relationship inventory checks for custom morph classes and database owner keys, and added MySQL grammar coverage for widened version strings. The final combined affected-area run passes 124 tests and 2,343 assertions; the review record lists independent overlapping shards and frontend verification.

Commit preparation additionally verified disposable prerequisite and final selected trees: 79 prerequisite regressions and 711 final-tree regressions pass. See [commit packaging verification](final-review.md#commit-packaging-verification). These are overlapping runs, not a new unique suite total.
