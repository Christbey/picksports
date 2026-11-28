# Player prop grading corrections

Candidate source contract reviewed November 25, 2026. This is stored-data correction only: no provider fetch, quote refresh, probability regeneration or real-money wager execution. Use [production access](production-access.md) and [database access](database-access.md) before operating on any remote target. No production repair was executed in this readiness pass.

## Current outcome and retained evidence

`GradePlayerProps` derives an outcome from the current stored final game and current stored player statistics. Each admitted write atomically updates the existing prop's outcome projection and appends a `player_prop_grade_revisions` receipt. Receipt revision numbers order local locked observations; they are **not** provider revision numbers. Many stat ingesters replace rows, so changed row IDs or timestamps alone do not constitute a new semantic result. The fingerprint uses matching player stat contents, matching policy and final-game evidence. Observed stat IDs are retained as metadata only.

The transaction locks game, current stats, matching identity rows, prop and receipt head. Stat replacement ingesters take the same game lock. Reads that determine grading/ownership are locking/current reads, avoiding a caller's older MySQL repeatable-read snapshot. All source and receipt models must use one database connection. Identical semantic evidence and a timestamp-consistent projection are a no-op, including after delete/reinsert of equivalent statistics. A matching graded receipt restores its known grading timestamp if the projection timestamp is missing or inconsistent; a pending projection must have no grading timestamp. If an incomplete matching receipt supplies no known time, a repair explicitly records the local observation time rather than inventing an original grading date. Timestamp repairs append prior/current audit and never rewrite the earlier receipt. A changed result appends prior/current projections and retained entry evidence rather than replacing the audit.

The first observation of an already graded legacy row is explicitly `legacy_grade_first_observation`: it retains the prior projection but does not invent which past stat revision produced it. The first receipt binds the frozen entry hash. A subsequent change to that admitted identity, line, prices or probability evidence refuses reconciliation and needs reviewed repair; the grader never silently reinterprets an old outcome against a changed entry.

Missing scalar/composite components, nonparticipation and ambiguous names remain pending rather than being assigned zero. If a previously graded prop loses sufficient current evidence, correction clears `actual_value`, `hit_over`, `error` and `graded_at`, preserving the old values in the append-only receipt. Explicit numeric zero remains a valid observation. A push has a non-null `graded_at`, actual value equal to the line, and null `hit_over`. Therefore **null `hit_over` alone does not distinguish pending from push**. Existing over-rate/Brier/calibration queries exclude pending rows and exclude pushes from binary outcome samples.

## Explicit bounded repair

Ordinary game-targeted grading revisits already graded props for that game. Season grading remains ungraded-only; this change does not launch a season-wide historical rewrite. Automatic correction occurs when the existing game-targeted flow invokes grading; it does not create a new universal stat-revision rediscovery scheduler.

Inspect an explicitly selected set of IDs first, against an approved target:

```bash
php artisan sports:reconcile-player-prop-grades basketball_nba --ids=101,102 --dry-run
```

Dry run is also the default without flags. Apply only after reviewing the scope and dry-run result:

```bash
php artisan sports:reconcile-player-prop-grades basketball_nba --ids=101,102 --apply
```

Provider sport keys are `basketball_nba`, `basketball_ncaab`, `basketball_wnba`, `americanfootball_nfl`, `americanfootball_ncaaf` and `baseball_mlb`; existing market mappings are retained. This does not add unsupported MLB grading formulas or change CFB market admission. IDs identify that sport's prop table, not provider/player/game IDs. Maximum scope is 100 explicit positive IDs; no implicit season or all-record selection exists. `--apply` and `--dry-run` are mutually exclusive. The entire scope's identity/existence and connection are validated before any apply write, so a missing ID refuses the batch without initial writes.

Each ID has its own atomic transaction; the batch is not globally atomic. A concurrent deletion, entry conflict or later runtime failure can leave earlier IDs committed. The command names per-ID status and applied count and returns failure for refusals. Resolve the cause and repeat the same explicit scope: unchanged receipts do not duplicate. Do not delete audit rows, overwrite frozen quotes or force missing statistics to zero to make repair succeed.

## Quote and probability preservation

Shared odds refresh retains graded or receipted rows and replaces only ordinary ungraded/unreceipted mutable quotes under the game lock. Fresh snapshots receive new rows where existing schemas permit them. CFB's unique `quote_key` retains a receipted quote even after its outcome becomes pending. No prior frozen row is remapped.

Name-based identity admission now requires one normalized exact full name within the game's two teams. A reviewed explicit mapped ID is accepted only on those teams. Approximate, partial, last-name-only and ambiguous matches stay unlinked; raw player name and quote are preserved. Existing historical guessed links are not bulk remapped because their original intent is unknown.

The analyzer skips graded/receipted candidates and serializes snapshot persistence under the same game→prop admission. Model save and numeric-mutation guards also cover quiet methods. Six typed source foreign keys use restrictive deletion, so receipt history cannot disappear through prop deletion or a game's cascading prop deletion. This deliberately refuses destructive historical deletion; plan retention and storage capacity accordingly.

## API/UI consumers and limits

The wire contract remains unchanged. `SportPlayerPropResource.grading` exposes the current outcome projection; `BettingRecommendationResource` supplies the recommendation cards' flat actual/hit/graded fields. `usePlayerPropPresentation`, `PlayerPropCard` and `NflPlayerPropCard` show corrected results or pending state. Historical operator provenance lives in the receipt ledger; no new public correction-history endpoint was added. Prop page cache invalidation is attempted after commit; failure cannot roll back a committed grade. Cache outages/TTL and deployment shared-cache behavior remain operational acceptance concerns.

Apply additive migration `2026_11_25_050000_create_player_prop_grade_revisions.php` before application/worker code and coordinate old writer drainage. Down refuses to destroy receipts; retain the table on compatible code rollback. Model creation validates exactly one matching typed source/sport/table/ID, including quiet saves; this candidate does not add a database CHECK for exactly-one polymorphic source identity. Foreign keys enforce source existence and retention. Privileged raw SQL/query-builder mutation can bypass model identity/append-only/entry guards and is outside the supported writer contract.

This repairs local outcome convergence from admitted current stored data, not upstream correctness. Generic ingestion does not supply an authoritative monotonic provider revision for these stats; a stale payload accepted later can still become the current row. Parser completeness/zero preservation, historical guessed links, source ordering, actual database concurrency, fresh supported-browser checks and production rollout remain separate gates. See [the implementation review](../contracts/player-prop-correction.md) for exact local evidence and review coverage.
