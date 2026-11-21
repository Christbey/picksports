# NBA structured research adapter and acquisition ordering

Implemented locally November 21, 2026. No deployment, production setting change, provider sync or automation activation was performed. NFL ingestion/research/review behavior and schedules remain unchanged. PDFs are optional manual corroboration.

## Acquisition ordering

The additive migration `2026_11_21_130000_add_nba_injury_acquisition_ordering.php` adds acquisition sequence/projection fields to immutable receipts and a mutable `nba_injury_team_states` pointer/counter. Existing receipts are retained without backfilling an invented acquisition order.

An NBA capture reserves a monotonically increasing team sequence under a short team-row lock before network acquisition. On completion it reacquires that lock. Only the newest issued acquisition can update the current projection, publish cache entries or advance the team pointer. Older responses retain immutable snapshots/receipts with `projection_applied=false`; receipt insertion order is not source precedence. A newer failed attempt remains the team head and cannot be hidden by an older successful response arriving later.

Research requires the head's sequence to equal the newest issued sequence. An in-flight or crashed newer attempt blocks structured admission, even if the older receipt is still fresh. The next successful acquisition recovers admission; there is no lease expiry that silently promotes an old response. Current injury rows survive failures. Network acquisition occurs outside the database lock; only reservation and persistence are serialized. Cache publication shares the completion lock so an older overlapping response cannot poison the newer cached result.

The counter is per team. Source-only assessment locks both participating teams in ID order while building/persisting its result. Subsequent acquisition or source-policy changes invalidate a stored assessment through the existing evidence hash comparison. Readers do not acquire network data, write source documents, dispatch work or buy reviews.

## Stored structured evidence

`StructuredInjuryEvidence` reads the verified NBA team heads directly. It does not manufacture prose and store it through the exact-excerpt document API. It emits `evidence_kind=structured`, the parser version `espn-nba-availability-v1`, snapshot/receipt IDs, payload/material hashes, entry reference and JSON pointer, original status/time fields, provider/publisher and team collection scope. Research documents and optional official reports retain their existing immutable source identities.

The adapter requires:

- Explicitly enabled, team-specific ESPN source registration of kind `structured_espn`, with source key `espn-nba-injuries:<ESPN team ID>` and approved ESPN hosts.
- A matching NBA canonical event, season/type and distinct participating teams, with authoritative pregame kickoff/freshness admission.
- A complete or explicitly empty current acquisition, known original observation time within the existing two-hour NBA policy, no capture issues, matching normalizer and current player-to-team/external-ID mappings.

Pending, partial, failed, stale, unmapped, revoked or out-of-scope evidence appears as an explicit unresolved limitation. It cannot borrow another team's timestamp or fall back to an older successful receipt. The production roster check is a point-in-time verification; mapping is checked again against current stored players when evidence is read.

Findings are labeled **ESPN-reported NBA availability**, never official NBA designations. `Day-To-Day` stays ambiguous; no-injuries-reported remains neutral and does not declare a healthy roster. Injury `date` remains an entry field, with `published_at=null`; it cannot become the collection's publication time. Lineup, rotation and rest/travel coverage remain unavailable without independent stored evidence. Simultaneous manual and structured availability material is flagged `source_reconciliation_required`; unstructured official text is not silently reconciled into machine-readable status claims.

The existing admin-only research API carries the optional structured provenance fields. The research panel displays the provider label, team-wide scope and observation time. NFL response fields retain their prior allowlist. OpenAPI and TypeScript document the optional NBA fields. Access permissions and existing routes are unchanged.

## Gates and explicit source-only operation

Both NBA flags default false:

```dotenv
NBA_INJURY_OBSERVATIONS_ENABLED=false
NBA_RESEARCH_SOURCE_REFRESH_ENABLED=false
```

The second flag enables the stored adapter and the explicit `nba:refresh-injury-research <NBA game ID>` command. It does **not** add a schedule, queue listener, paid review, auto-registration or acquisition. The command calls `GameResearchService::assess` directly, preserving forecasts/approvals and never dispatching `RefreshGameAnalysis`. Shared/NBA research capability gates must also be enabled for assessment.

For an approved nonproduction trial only, register each participating team using the existing source command. Replace the placeholders with verified IDs; this example is not an executed production command:

```sh
php artisan sports:ingest-research-document nba 'espn-nba-injuries:<ESPN_ID>' \
  --register-source --register-only --kind=structured_espn \
  --team-id=<INTERNAL_NBA_TEAM_ID> \
  --url='https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/teams/<ESPN_ID>/injuries?limit=100' \
  --allowed-host=sports.core.api.espn.com --allowed-host=site.api.espn.com --enable-source
php artisan nba:refresh-injury-research <NBA_GAME_ID>
```

An [isolated release candidate and end-to-end adapter canary](nba-adapter-release-candidate-2026-11-21.md) are now prepared and verified. Retention/overnight coverage, activation scope and paid-review material deduplication remain separate gates. A fresh receipt still changes provenance/evidence identity; the stored material hash is available for future review reuse, but this implementation does not extend a paid interpretation's original validity or enable automation.

Rollback disables the two NBA gates and retains all evidence/state tables. Disabling the adapter alone restores the prior manual-document evidence path. Do not run destructive migrations, reset counters, edit immutable receipts or change NFL flags as an operational rollback.

## Production roster verification

Read-only Laravel Cloud inspection and one ESPN roster GET for each of 30 NBA teams verified **528 provider roster players**, with **zero missing or wrongly assigned current roster identities**. No application API credential was available, so the inspection used an explicit production database `READ ONLY` transaction without Laravel bootstrap, provider ingestion or writes.

Of 6,946 stored NBA injury rows, 990 have historical team IDs different from the player's current team; all 990 are inactive. The 105 active rows have zero team mismatches; raw injury athlete IDs have zero mismatches. There are also 217 stored player identities absent from the current provider rosters, retained as history. No cleanup, roster mutation or timestamp rewrite was performed.

See the [dated mapping evidence](nba-production-roster-mapping-2026-11-21.json) for command IDs, source URLs, timestamps, per-team counts and observed gate values. The new flags were absent from production configuration; analysis scheduling/publisher flags remained false and the inspected NFL flags remained true. Production was not activated.


## Verification results

The expanded suite passes **103 tests / 549 assertions** on SQLite and on disposable **MySQL 8.4.6**. All 341 migrations apply; additive observation/ordering rollback/reapply is covered. Tests include overtaking responses with cache enabled/disabled and newer success/failure, pending and stale team isolation, roster remapping, explicit empty collections, source revocation, manual-source reconciliation, canonical identity, original NFL writers/research compatibility, admin access controls and read-only API behavior.

A separate two-process PHP/MySQL race deliberately delayed the older acquisition until the newer worker committed. The older response then received the greater receipt ID, but `projection_applied` stayed false and the team head/current injury status remained from the newer acquisition. No external requests were made for this race. A crashed newer acquisition intentionally keeps structured coverage pending until a new acquisition succeeds; automated recovery/scheduling is not enabled.

Six frontend research tests, Vue/TypeScript checking, scoped ESLint/Prettier, PHP Pint and whitespace checks pass. The OpenAPI artifact matches its generator. See the [verification evidence and source hashes](nba-structured-adapter-verification-2026-11-21.json). The prior live ESPN capture canary remains recorded separately; the new research assessment adapter has fixture/MySQL verification, with the subsequent [release-candidate canary](nba-adapter-release-candidate-2026-11-21.md) completing real observation-to-assessment and authenticated API verification outside production.

The disposable MySQL instance and its temporary database/harness files were removed after exporting evidence. Existing local environment files and production settings were not changed.
