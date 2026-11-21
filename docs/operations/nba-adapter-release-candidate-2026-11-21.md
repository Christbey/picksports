# NBA structured research release candidate — November 21, 2026

**Ready for code review; not pushed, deployed or activated.** The isolated candidate is on local branch `codex/nba-structured-research-rc`, based on the verified production commit `39807a44a55187fa3d7b2f15a54f3d876a2a0742`. Its checkout is `/tmp/picksports-nba-adapter-rc`. The unrelated dirty workspace was not staged or reset.

## What is included

NBA-only timestamp normalization, immutable team snapshots/receipts, ordered acquisition heads and cache publication, the stored structured research adapter, explicit source-only assessment command, optional provenance fields in the existing admin research API/panel, two additive migrations, tests and operational evidence. Both new flags default false. PDFs remain optional.

The release retains **537 protected baseline files byte-for-byte**, including NFL implementation/tests/configuration, the shared injury base with its NFL retirement hook, existing research assessment/NFL compatibility services, review execution, schedules, API routes and dependency manifests/locks. Deployed NBA prediction weights and unrelated API query schemas are also retained; the dirty workspace's unrelated changes were excluded.

See the [candidate manifest](nba-adapter-release-candidate-2026-11-21.json) for exact code hashes. Earlier 103-test/341-migration reports refer to the original dirty development tree; this candidate instead uses the production baseline and has **354 migrations** and **107 focused tests**.

## End-to-end result

**Passed on the release candidate with disposable MySQL 8.4.6.** An actual upcoming event was discovered from ESPN's current-day NBA slate, then its two rosters and injury collections were acquired. Two explicitly registered local ESPN sources produced complete team snapshots (three and five injuries) and eight structured availability findings. The run made 13 bounded live ESPN GET requests and no PDF/AI requests.

The canary used synthetic regression forecasts and an approval only to prove they were unchanged; it did not create a real betting recommendation for the event. Actual source IDs, original times and source URLs are in the [canary evidence](nba-adapter-e2e-canary-2026-11-21.json).

- Source-only assessment was `current` and correctly `partial`: both teams had availability coverage, while lineup, rotation and rest/travel remained unresolved.
- Authenticated admin reads through both legacy game ID and canonical event public ID returned HTTP 200 with provider/scope/reference metadata and no research GET writes. Only unrelated user-activity tracking was disabled in the harness; normal authentication and authorization remained enabled.
- Cached repeat acquisitions appended receipts, reused snapshots and retained original network observation times without extra provider requests. Reassessment and source revocation were verified; revocation invalidated the saved assessment and removed that team's structured coverage on reassessment.
- Disabling the adapter refused further source-only commands. Forecasts, market projections, approvals and all NFL tables remained unchanged. Queued jobs, analysis work items and AI generations stayed zero.

The canary packet is dated evidence, not an evergreen source packet for future activation. Its ordinary two-hour source validity is unchanged. Reacquire and reassess the actual target event when activation is separately approved.

## Validation

- Fresh candidate MySQL migrations: **354 applied**, including the two additive NBA migrations.
- Candidate regressions: **107 passed / 553 assertions** on both MySQL and SQLite; no remaining warnings after creating a credential-free ignored test environment file.
- Six frontend research tests passed. Vue/TypeScript, scoped ESLint/Prettier/Pint, Composer platform requirements, OpenAPI generation and whitespace checks passed.
- The production asset build passed. PHP autoload was verified to load the isolated candidate, using a separate copy of the installed locked vendor dependencies; no dirty application autoloader was symlinked into the candidate.

The disposable database is removed after evidence export. The local candidate's ignored `.env` contains only a comment, no credentials or activated gates. No production database, source catalog, environment variable, cache, worker, scheduler or deployment was modified by this canary. Read-only Cloud metadata confirmed the deployed baseline before assembly.

## Review and future rollout sequence — not executed

1. Review the branch diff against the production baseline and the manifest/canary evidence. Confirm the production baseline has not advanced before any future deployment. No push has been made because production tracks `main` with automatic deployment.
2. If code deployment is separately approved, deploy the exact candidate with **both new flags absent/false**. The existing deployment migration command is `php artisan migrate --force`; retain all existing production settings and worker/scheduler configuration. Inspect additive table/index/FK presence and existing NFL operation before any feature activation.
3. Review acquisition capacity and rollout scope before setting `NBA_INJURY_OBSERVATIONS_ENABLED=true`. This gate affects **all existing NBA injury jobs**, including already scheduled all-team ingestion; a team-specific manual command does not limit that global gate. The nonproduction canary was bounded to two teams. A narrow production capture rollout would need a separately reviewed team admission control or an agreed all-team capture rollout.
4. When source-only activation is separately approved, explicitly register approved team-specific `structured_espn` sources using the [adapter runbook](nba-structured-research-adapter.md), set `NBA_RESEARCH_SOURCE_REFRESH_ENABLED=true`, and run `php artisan nba:refresh-injury-research <VERIFIED_NBA_GAME_ID>`. Require current complete receipts and matching teams/event before assessment. This command does not acquire sources, queue work or buy a review.
5. Keep `GAME_ANALYSIS_SCHEDULE_ENABLED` and `GAME_ANALYSIS_PUBLISHER_ENABLED` false. Paid-review material deduplication/original validity, retention/storage and overnight freshness/capacity remain separate gates before any automation decision. Keep all NFL gates unchanged.

## Rollback — not executed

Set only `NBA_RESEARCH_SOURCE_REFRESH_ENABLED=false` to return research to the prior manual-document path. If capture also needs rollback, set `NBA_INJURY_OBSERVATIONS_ENABLED=false`; rebuild configuration and restart the affected workers using the approved Cloud release procedure so processes read the new values. Existing NBA ingestion then follows the previous path, including its prior timestamp behavior. Retain corrected rows, all snapshot/receipt/state tables, source records, assessments and accounting. Do not run migration down, reset sequences, clear shared queues/budgets, disable existing manual NBA research/review or change NFL settings. The previous production code remains compatible with the additive schema if code rollback is needed.
