# Structured injury feed audit — November 21, 2026

Scope: existing structured injury acquisition, persistence, research suitability and replacement of the PDF-first plan. No application behavior, provider schedule, NFL setting or automation flag was changed. No provider refresh or paid call was triggered. Production inspection used an authorized read-only database transaction through Laravel Cloud because application API authentication was unavailable.

## Decision: replace the routine input, preserve publisher identity

Use our existing **ESPN JSON injury pipeline** as the first candidate for routine NBA research evidence. The application should label it **ESPN-reported availability**. It must not describe that evidence as an official NBA game-day submission or silently inherit the official report's authority.

Keep the official NBA report as optional corroboration/fallback. If the product requires league-official status, we need a verified NBA-origin or appropriately authorized structured feed whose actual fields, timing and provenance meet that requirement. This audit did not establish such a replacement. A structured format alone does not establish official provenance or equivalent game-specific coverage.

No new commercial provider is selected. Sportradar documents a [structured NBA injuries endpoint](https://developer.sportradar.com/basketball/reference/nba-injuries), but its listed four-hour cache and active-injury scope require further validation against our two-hour research policy and event requirements; access and official-report equivalence are unverified. A third-party package advertised as an NBA injury API actually [downloads and parses PDFs](https://raw.githubusercontent.com/mkeenan195/nba-injury-report/main/src/nba_injury_report/report.py), so adopting its JSON output would retain PDF extraction underneath.

## Existing acquisition paths

| Path | Evidence from repository | Consequence |
| --- | --- | --- |
| NBA | `espn:sync-nba-injuries` → queued `FetchPlayerInjuries` → NBA `SyncPlayerInjuries` → shared abstract action | Reuse existing acquisition rather than add another league-wide poller |
| NBA primary | Configured ESPN core `/teams/{teamId}/injuries?limit=100` | Structured team feed; no verified official NBA submission contract |
| NBA fallback | ESPN site roster `athletes[].injuries` | Record fallback origin and completeness independently |
| NBA persistence | Mutable `nba_player_injuries`, per-player raw payload/status/detail/date | Useful current projection, insufficient immutable team observation history |
| NFL | ESPN subclass plus existing injury snapshots and NFL-specific activity/retirement behavior; separate NFL/nflverse/research consumers | Preserve existing paths and semantics; NBA work must not rewrite the shared base wholesale |
| Other sports | ESPN commands/adapters exist for WNBA, MLB, CBB, WCBB and CFB; CBB has customized handling in the workspace | Shared naming is not proof of identical contracts. Keep their behavior unchanged in the NBA rollout |

Owning files: [NBA action](../../app/Actions/ESPN/NBA/SyncPlayerInjuries.php), [shared action](../../app/Actions/ESPN/AbstractSyncPlayerInjuries.php), [NBA job](../../app/Jobs/ESPN/NBA/FetchPlayerInjuries.php), [dispatch command](../../app/Console/Commands/ESPN/AbstractSyncInjuriesCommand.php), [provider client](../../app/Services/ESPN/BaseEspnService.php), [configuration](../../config/espn.php), [injury API query](../../app/Services/Api/V2/SportInjuryQuery.php), and [scheduler](../../routes/console.php).

The seven audited deployed file hashes match the reviewed `39807a44` worktree. The main development workspace contains unrelated differences, including the shared injury action. Findings distinguish deployed code from pending workspace changes; no such changes were copied or deployed during this audit.

## Production observations

At **12:23:30 a.m. Central, November 21**:

| Observation | Result | Limit |
| --- | --- | --- |
| NBA injury rows | 6,923 stored; 106 active across 30 teams | Row presence does not prove complete current team responses |
| Active status values | 94 `Out`, 12 `Day-To-Day` | Broad statuses are not an exact replacement for game-specific NBA designations |
| Latest row observation | November 20, 10:32:12 p.m. Central | Unchanged polls also write `updated_at` |
| Latest injury command heartbeat | November 20, 10:32:06 p.m. Central | This command acknowledges queue dispatch, not completed team ingestion |
| Pilot teams | MIL: one active row, observed 10:32:08 p.m.; PHI: six, observed 10:32:09 p.m. | Do not infer a missing-player count by comparing injury rows to a report that also lists roster assignments |
| Immutable injury snapshot tables | NFL snapshot and snapshot-entry tables only | No NBA team snapshot/completion ledger was found |
| Shared automation | Scheduler false; publisher false | Remained off throughout this audit |

The configured NBA injury schedule runs at minute 2/32 within 08:00–23:00 Central, in season. Consequently, the last normal run is 22:32 and the next is 08:02. That overnight gap cannot support a universal two-hour evidence guarantee. Its effect must be addressed deliberately for NBA; do not change NFL schedules to solve it.

[Redacted query evidence and code hashes](nba-structured-injury-feed-audit-2026-11-21.json) retain command IDs, observed times, aggregate counts and per-team timestamps. No conclusion here relies on local development injury data.

## Gaps before structured evidence can drive automated research

1. **Timezone loss is confirmed.** The shared normalizer parses a timezone-aware provider value and serializes it without converting to application timezone. Row `451120` contains raw `date=2026-11-21T04:06Z` but stores `source_updated_at=2026-11-21 04:06:00`. The correct Central representation of that instant is November 20 at 22:06. Read as local time, the current value appears six hours late. Fix the NBA ingestion boundary and test mixed offsets/DST; do not blindly subtract six hours from historical rows.
2. **The timestamp's meaning is not guaranteed.** In sampled rows `lastUpdated` is absent; `source_updated_at` came from the raw `date` fallback. Preserve the original field and its meaning. An entry date is not automatically a report publication/update time. Old entry dates can describe long-running injuries that a newly verified complete feed still reports.
3. **There is no team completion receipt.** Empty-but-valid responses, failed responses, malformed payloads, fallback usage, pagination and unmatched players cannot be reconstructed reliably from current rows alone. Current API freshness also uses table-wide maxima, which can hide one stale team behind another team's update; its default 48-hour threshold is not the research layer's 120-minute policy.
4. **Completeness is under-validated in the deployed NBA path.** It accepts an array as an observed payload, checks common collection keys and follows individual references, but does not establish total/page completeness. The configured limit is 100. A malformed/partial array or fallback failure must not be treated as a proven empty healthy roster or used to retire missing entries.
5. **Unmapped players are dropped from the current projection.** The abstract action prepares snapshot rows before player matching, but NBA inherits a no-op historical snapshot hook. Preserve those raw entries and mark identity coverage partial; do not silently lose them.
6. **Observation and material change are mixed.** Successful unchanged rows advance `updated_at`; the client caches payloads without returning original fetch metadata. We need network/cache provenance and a team observation time distinct from status-change time and injury date.
7. **The research bridge is missing.** Current research reads admitted `ResearchDocument` evidence, not `nba_player_injuries` directly. Add a versioned structured adapter with raw-payload/JSON-pointer references rather than manufacturing prose and calling it an exact source quotation.
8. **Review churn remains a separate risk.** New observation IDs/times must not buy a review when the actual facts, forecast and decision are unchanged. Preserve new provenance and audit receipts without rewriting the paid revision or extending its original validity silently.

These are implementation findings, not changes made by this audit. The [updated source refresh plan](nba-source-refresh-plan.md) makes structured ingestion/verification the primary path and PDFs optional.
