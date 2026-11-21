# NBA injury timestamp and snapshot implementation

Implemented locally November 21, 2026; not deployed or activated. Production settings, NBA/NFL schedules and research/review automation were not changed. PDFs remain optional. This is the acquisition foundation in the [source refresh plan](nba-source-refresh-plan.md), not the completed research adapter.

## Activation boundary

`NBA_INJURY_OBSERVATIONS_ENABLED=false` is the default, including when the variable is absent. While disabled, the NBA action delegates to the existing shared implementation. Enabling it opts NBA ingestion into corrected timestamp normalization and evidence capture together. No NFL/shared action, API/resource shape, frontend, prediction input, research approval, scheduler or paid review entry point is changed.

The existing NBA injury job still invokes the same NBA action. There is no second poller, new schedule, PDF dependency, research dispatch or OpenAI call. ESPN material remains **ESPN-reported availability**, not an official NBA report. Existing incorrect timestamps are not bulk rewritten; corrected values come from subsequent verified acquisitions after activation.

## Stored evidence

The additive migration `2026_11_21_120000_create_nba_injury_observation_tables.php` creates:

| Table | Meaning |
| --- | --- |
| `nba_player_injury_snapshots` | Content-deduplicated team payload, provider/source URL, normalizer version, raw/payload/material hashes, completeness outcome and issues |
| `nba_player_injury_snapshot_entries` | Historical normalized entries, original JSON, external athlete IDs, mapping at capture, original time field/value and normalized timestamp |
| `nba_player_injury_observations` | One receipt per completed acquisition attempt, including unchanged polls, empty collections, partial responses and provider failures; start/completion, original network observation and cache metadata |

Snapshots have `complete`, `empty`, `partial` or `failed` outcomes. Raw response payloads are retained in database JSON, bounded to 2 MiB per response and 4 MiB per acquisition. Raw entries for unmapped players remain available. Historical player IDs deliberately do not cascade with current-roster deletion. Team and snapshot foreign keys restrict deletion.

Snapshot/entry/receipt models reject Eloquent updates and deletes. This is application-level append-only storage, not a database trigger or protection against direct SQL/query-builder writes. Do not use bulk writes or retention jobs to modify this evidence. Snapshot content is deduplicated; repeated checks append receipts rather than rewriting historical content.

## Time and failure semantics

- Offset-aware source timestamps are converted to the application timezone before SQL storage. UTC, Eastern offsets and Central DST cases are covered. The original value and field (`lastUpdated` or fallback `date`) remain attached to the entry. A fallback injury `date` does not acquire report-publication authority.
- Missing timestamps remain unknown. Timezone-free source values remain raw, normalize to null, and mark coverage partial instead of assuming an offset.
- The NBA-specific cache namespace stores original successful network completion times. Cache reads get a new check/completion time but retain the original observation time. An observation spanning references uses the oldest required response time; unknown required times remain unknown.
- Explicit empty collections are recorded and may retire current rows. Count/pagination inconsistencies, unresolved references, missing identity/status, unmapped athletes, team mismatches, ambiguous collections and unknown roster coverage prevent absence-based retirement. Failed primary acquisition may use an explicitly identified roster fallback; missing `injuries` is not an empty injury list.
- Reference requests are limited to approved ESPN NBA hosts/paths, HTTPS, no redirects, and at most 100 distinct references. Response sizes and HTTP timeouts are bounded.
- Network acquisition happens before the team lock. Current-row projection and immutable evidence persist in one transaction. Evidence persistence failure rolls back the projection and fails the job; it cannot leave a successfully updated projection without its receipt. A database/storage failure cannot itself promise a durable failure receipt.

## Automated regression verification

The focused suite passes **48 tests / 178 assertions**: 26 NBA observation cases, nine existing NFL injury/history/write cases, and 13 migration safety cases. HTTP is faked with stray requests prohibited; queued work is asserted absent. Migration down/up is exercised only in disposable SQLite, preserving NBA/NFL team records, NFL injury schema and captured configuration values. Transaction failure is explicitly tested.

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: bash scripts/php85.sh artisan test \
  tests/Feature/ESPN/NBA/SyncPlayerInjuryObservationsTest.php \
  tests/Feature/ESPN/NFL/SyncPlayerInjuriesTest.php \
  tests/Feature/ESPN/NFL/SyncPlayerInjuryWriteEfficiencyTest.php \
  tests/Architecture/MigrationSafetyTest.php --compact
```

Scoped Pint formatting passes. The original regression run used disposable SQLite and faked HTTP. The subsequent MySQL verification and real ESPN canary below were separately authorized; neither accessed production or called AI.

## Disposable MySQL and real acquisition verification — November 21

**Passed.** See the [machine-readable evidence](nba-injury-observation-verification-2026-11-21.json), including reviewed source hashes, constraints, receipt IDs, observation times and request URLs. This verifies the current working tree, not a clean release candidate or deployed Cloud environment.

- A fresh MySQL **8.4.6** instance used a private temporary Unix socket with TCP networking disabled. All **340 migrations** applied, and the focused suite passed **48 tests / 178 assertions** on MySQL. The NBA migration rollback/reapply test preserved NBA/NFL fixtures, NFL injury schema and configuration values.
- Six additional MySQL probes passed: duplicate snapshot identity rejected; snapshot/team, entry/snapshot and receipt/snapshot foreign keys enforced; referenced snapshot deletion restricted; invalid native JSON rejected. Each negative-write probe was rolled back. Key columns are matching unsigned big integers.
- The live canary acquired **one NBA team (ESPN ID 23)** with 17 roster players seeded solely into the disposable database. Its primary feed and three resolved references produced a **complete, three-entry snapshot with no issues**. There were five live requests in the successful canary: one roster discovery, one primary injury feed and three references. An initial verification-harness attempt made one extra roster request before stopping on a fingerprint-ordering assumption; it did not run NBA acquisition. No application fix was needed.
- A second acquisition used cached payloads, reused the same immutable snapshot and appended a separate receipt. Original observation time remained **14:11:01 Central**, rather than becoming the later check time. First acquisition took about **1.18 seconds**; cached repeat about **5 milliseconds**. Peak PHP memory was **52.5 MiB** for this small canary; this is not a load benchmark.
- Every retained source time represented the same instant after normalization, including `2026-11-21T14:55Z` → `2026-11-21T08:55:00-06:00` and an October timestamp using Central daylight offset `-05:00`. These entries supplied fallback `date` fields, not verified league report publication timestamps.
- Three additional HTTP responses were **simulated**, not provider requests: count-mismatch empty payload, then primary/fallback HTTP 503. Receipts became partial/failed and current injury rows stayed byte-for-byte unchanged. Live empty/failure conditions were not induced upstream.
- All NFL table schemas/data matched their before/after fingerprints. The existing NFL behavior tests passed on MySQL. Queued dispatches, AI calls and PDF requests were zero. Only ESPN NBA hosts were permitted by the canary harness; its default database was explicitly checked against the private disposable target.

The disposable server was shut down and its database files removed after evidence export. No production connection, environment update, deployment, scheduler run or automation activation occurred. The capture flag was enabled only in the isolated canary process; local `.env` and production settings were untouched.

## Subsequent implementation

The [NBA structured adapter and ordering record](nba-structured-research-adapter.md) supersedes the concurrency, roster-mapping and adapter implementation items below. It records 103 passing tests on MySQL, a real two-worker ordering test and all-team read-only production roster verification. Neither stage is deployed or activated.

## Original remaining gates before activation

1. Build an isolated release containing only the reviewed NBA changes; this workspace contains unrelated pending work. Disposable MySQL 8.4 verification, foreign keys and rollback/reapply have passed as recorded above. Verify the isolated artifact contains those exact reviewed files and leave the flag false during any future deployment.
2. The bounded nonproduction acquisition canary has passed for one team. Before promotion, verify overlapping/out-of-order acquisitions, current production roster mapping compatibility and worker-scale resource usage. Define which receipt a research reader may promote. A single-team canary and fake empty/failure cases do not prove all-team coverage or queue concurrency.
3. Implement and verify the structured snapshot-to-research adapter, game/team mapping, per-team freshness/coverage admission, source conflicts and NBA-only material review deduplication. No such adapter is dispatched by this change. The future `NBA_RESEARCH_SOURCE_REFRESH_ENABLED` gate remains unimplemented.
4. Review retention/storage and overnight acquisition coverage before enabling source-refresh automation. Existing scheduling does not guarantee round-the-clock two-hour freshness. Keep shared analysis/publisher automation off until the entire flow is verified.

Future capture rollback: set only `NBA_INJURY_OBSERVATIONS_ENABLED=false`, rebuild configuration/restart affected workers through the established release procedure, and retain all three tables and evidence. This restores the previous NBA ingestion path (including its prior timestamp behavior); it does not undo corrected rows or alter NFL. Do not drop evidence tables as routine operational rollback. No activation or rollback command has been run in production.
