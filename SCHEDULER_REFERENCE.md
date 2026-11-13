# Scheduler Reference

This document replaces the old sport-specific automation docs.

The source of truth for scheduled jobs is:
- `routes/console.php`

If this document and `routes/console.php` ever disagree, trust `routes/console.php`.

## Memory and execution boundary

The web scheduler dispatches work through `ops:dispatch-scheduled`; it does not
execute heavy sport commands. `RunScheduledCommand` coalesces duplicate queued
or running commands and uses the existing single `sync` consumer to serialize
bulk calculations. Live scoreboard/detail dispatch and CFB live betting retain
the separate `default` queue. CFB `--queued` commands also dispatch off web compute and keep their own
bounded batch jobs and completion heartbeats. Cadences, season guards and command arguments are retained.
Worker completion, not dispatch, records execution heartbeats. See
`docs/operations/laravel-cloud-queue-workers.md` for timeouts and rollout checks.

## Overview

The app uses Laravel's scheduler for:
- sport data sync pipelines
- prediction generation pipelines
- odds, injuries, futures, and player prop refreshes
- maintenance jobs
- alert digest delivery

Most sport jobs are season-gated in `routes/console.php` so they only run during relevant months.

## Event-driven finalization

Scoreboard and game-detail ingestion dispatch `GameFinalized` after final scores are present. `TriggerGameFinalizationGrading` runs on the queue, uses a per-sport/game uniqueness lock and overlap middleware, and reloads the game before canonical evaluation. Queue workers are required.

When `prediction_lifecycle.canonical_pipeline.{sport}` is enabled, finalization also evaluates canonical pregame forecasts for all seven supported sports, independently of `canonical_reads`. Already-final games are rediscovered when canonical results/evaluations are missing, scores change, or the scoring version needs updating. Existing final-clock and minimum-final-age guards apply; a subsequent sync can rediscover work once those guards pass. Identical observations are idempotent, while corrections and reversions append result/evaluation history.

Legacy prediction grading and the existing supported prop graders continue alongside canonical evaluation. Daily season-gated canonical evaluation commands remain recovery backstops at their existing times. Scheduled live update entry points and cadence remain unchanged. Live writers now append independent snapshots through `LivePredictionStore` (CFB retains its specialized snapshot stream), and freshness checks read that same source. Deploy `2026_10_28_193708_create_live_prediction_snapshots_table` before the new code. Historical grading remains active. When both canonical pipeline and canonical read switches are enabled for a sport, morning legacy generation schedules are skipped and registry predict/full steps use native generation. Existing `generate-predictions` entry points route to native generation after cutover; direct active legacy writer calls are rejected. After both CFB switches are enabled, registry predict/full plans also omit `cfb:update-adaptive-calibration`: its mutable historical profile is not consumed by canonical generation. Pipeline-only or reads-only deployments retain that step with compatibility generation. Explicit historical calibration, legacy grading, and the independent frozen canonical calibration schedules remain available. A pipeline-only deployment retains compatibility generation for its legacy readers. Switch values are unchanged by this code.

Shared registry predict/full plans now run `evaluate-canonical-predictions` before Elo, metrics and generation when canonical pipeline ownership is enabled. Pipeline-only cutovers also retain `grade-predictions` before compatibility consumers (including CFB adaptive calibration). With both switches enabled, registry plans omit the redundant legacy grading step. A read-only cutover does not change grading ownership. Explicit historical grading commands, event-driven historical/prop grading and daily recovery schedules retain their existing behavior and cadence; no switch or approval changes are made.

MLB probable-pitcher refreshes use the same retirement gate. Post-odds `generate-predictions` at `:30` routes to native generation and retains its original heartbeat. Native MLB generation materializes F3/F5 features from canonical inputs; deploy `2026_10_30_175657_add_forecast_lineage_to_mlb_period_feature_snapshots` before the source-separated feature store. Existing rows stay historical, and native features are pinned to the selected safe revision or explicitly unknown pitcher context. Source changes during materialization are held, and period inference rejects features from another native revision. Cadence and model approval holds are unchanged; subsequent tabular/period shadow inference writes explicit native lineage. Deploy `2026_10_28_195953_add_canonical_prediction_to_shadow_model_outputs` first. Native shadow observations remain tracking-only and hold `canonical_shadow_inputs_unvalidated`; existing artifact promotion does not approve the new input contract. The shared shadow-decision recorder processes ascending-ID batches of 100 with a fixed per-run upper ID, respects its optional limit, and resolves each observation through its recorded native or historical source. Its scheduled times, source order, exact quote gates, and immutable decision identity remain unchanged. Coverage, order, freshness, and monitoring checks use canonical evidence after cutover. Native publication invalidates forecast caches and queues the existing supported narratives after commit; coverage repair keeps narrative work suppressed. Queued legacy NFL shadow jobs do not revive retired writers. Historical corpora and backfill commands are retained.

The scheduled `sports:ai-daily-predictions` publisher follows each sport’s canonical read switch. Native analysis uses verified frozen inputs and a separate `canonical_prediction_id`; legacy analyses retain their original IDs. AI can explain or downgrade stored exact-revision public decisions, and cannot approve a tracking candidate. Changed or withdrawn forecasts and approvals are rechecked before persistence. Deploy `2026_10_29_121751_add_canonical_prediction_to_sports_ai_prediction_analyses` before this code; rollback refuses to discard populated native lineage. Existing schedules, limits, retries, and provider settings are unchanged.

The MLB `08:40` `mlb:snapshot-bet-filter` job now follows the canonical read switch through `BettingForecastSource`. Native observations retain verified run/input lineage under a separate prediction foreign key and the existing `canonical_approval_required` tracking hold. Grading uses the observation’s stored native line; historical legacy grading remains available, and reports separate the two sources. Deploy `2026_10_29_153220_add_canonical_prediction_to_mlb_bet_filter_results` before this code. The season gate, cadence, market enablement, and feature switches are unchanged.

The weekly `sports:report-epa-blend-calibration` reports for NFL/NBA/CBB/WCBB follow either sport's canonical switch. Native NFL reports grade frozen recommendation forecasts with matching recommendation EPA metadata; native basketball reports grade canonical forecasts and their recorded EPA metadata. Missing native EPA application evidence stays in an unknown cohort. Input hydration is batched and summaries stream; explicit `--forecast-source=legacy` retains historical inspection. NFL `--model-version` selects the frozen recommendation version (current by default). Cadence, season guards, heartbeat names, observational interpretation, approval rules and betting holds are unchanged.

NFL overall accuracy reporting (`nfl:analyze-predictions`) now selects frozen canonical recommendation probabilities, home-margin projections and metadata after either NFL switch is enabled. Historical readiness explicitly passes `--forecast-source=legacy`; weekly cadence, approval rules and generation are unchanged. Native missing forecasts remain excluded, and missing trust/edge evidence remains unknown.

NFL moneyline backtests also use canonical frozen recommendation probability/trust and paired moneyline quotes after either NFL switch is enabled. The historical readiness replay pins `nfl:backtest-moneylines --forecast-source=legacy`; its cadence and approval rules remain unchanged. Native missing evidence is excluded and report qualification grants no betting approval.

NFL spread and totals backtests select frozen recommendation forecasts and same-input market lines after either NFL canonical switch is enabled. The historical `nfl:readiness-pass` explicitly retains `--forecast-source=legacy` for these backtests, alongside its historical point/reason audits. Direct native inspection accepts `--model-version` (current by default); missing native inputs remain excluded and uncaptured closing lines leave CLV unavailable. Native reads are batched, and grading math, total-rule play/watchlist gates, historical market-quality warnings, schedules and approval rules are unchanged.

## Core Scheduling Helpers

The scheduler is organized around shared helpers in `routes/console.php`:
- `scheduleSportPipeline()`
- `schedulePredictionPipeline()`
- `scheduleDailySeasonJob()`
- `scheduleWeeklySeasonJob()`
- `scheduleHalfHourlyWindowJob()`
- `scheduleOddsSyncWindow()`
- `schedulePlayerPropsWindow()`
- `scheduleEpaLifecycle()`

## Current Sport Pipelines

### NBA

- Basketball season arguments use the ending year: October 2026 through June 2027 is season `2027`.
- Check NBA and CBB missing pregame prediction coverage every five minutes for today plus eight days, covering the rolling canonical-readiness horizon. At minute 17 of every hour, run inline (away from the five-minute coverage tick and without a detached child) to update ending-year Elo ratings without reapplying already processed games, rebuild regular-season and postseason team metrics, then refresh eligible upcoming forecasts using stored inputs. A failed metric rebuild prevents forecast refresh and fails the recovery heartbeat. Pipeline checks recognize successful recovery and allow 75 minutes for the next hourly refresh; overdue dependencies still fail. Futures validation uses the basketball ending-year season. NBA canonical generation defaults to a bounded eight-day rolling horizon (`--days-forward`); explicit `--game` and `--date` requests retain their targeted scope. CBB coverage remains missing-only with its widened eight-day horizon and reports known placeholder teams as holds without failing the command; WCBB keeps its existing two-day coverage window and explicit metric holds. NBA hourly refresh retains its existing seven-day scope. WCBB logs missed tipoffs and unusable metric holds without failing coverage. Genuine WCBB generation errors still fail the command. NBA and CBB report started games missing forecasts as failures. No retrospective pregame predictions are created. Checks use existing stored inputs and do not fetch odds or props.
- Daily scoreboard imports run inline before the morning prediction pipeline, so generation does not depend on queued imports finishing first.

- Daily scoreboard sync at `01:00`
- Live scoreboard sync every 5 minutes between `18:00` and `03:00`
- Game details sync every 30 minutes between `18:00` and `03:00`
- Grade predictions at `03:30`
- Evaluate immutable canonical predictions at `03:35` when `PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE=true`
- Calculate Elo at `04:00`
- Calculate team metrics at `04:30`
- Generate predictions at `05:00`
- Generate canonical predictions at `05:05` when `PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE=true`
- Generate playoff forecast at `07:35`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `10:00` and `14:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run NBA EPA lifecycle jobs via `scheduleEpaLifecycle()`

### CBB

- Active October–April, including schedule preparation before opening day. Uses the ending-year season and the five-minute pregame coverage check described above.
- Daily current-week scoreboard import runs inline before prediction generation.

- Sync tournament structure daily at `01:00`
- Sync teams weekly on Sunday at `01:15`
- Sync all team schedules weekly on Sunday at `01:30`
- Sync season-specific CBB rosters daily at `02:15`, with the explicit ending-year season and `--include-prior` to seed a missing previous-season observation once. The scheduled refresh runs inline (`--sync`) ahead of the `06:30` pregame pipeline; manually dispatched jobs still use the `sync` queue. Immutable observations exclude athletes absent from the latest verified roster without deleting player identities or historical stats. Failed/empty/partial provider responses retain the previous observation; model evidence marks stale or missing rosters. Expected provider rejections and missing historical rosters are counted as coverage warnings, separately from verified current refreshes. Unexpected write failures, or an inline batch with no verified current roster, still fail the job.
- Backfill stale games daily at `03:00`
- Daily current-week sync at `02:00`
- Live scoreboard sync every 5 minutes between `12:00` and `01:00`
- Game details sync every 30 minutes between `14:00` and `02:00`; only real active/final games in the requested date window qualify. Unattempted games precede retries, with a 30-minute retry cooldown and persisted attempt/completion status.
- Run the locked CBB pregame pipeline at `06:30`: inline scoreboard ingest, up to 25 eligible detail imports, grading, Elo, validated team metrics, then the configured legacy or canonical generator. A detail failure is reported while forecasts can continue using validated priors; other failed dependencies stop the pipeline.
- Report read-only season readiness at `07:00`, including missing forecasts, final box-score reconciliation, provisional inputs, and reconstruction counts.
- Evaluate immutable canonical predictions at `05:05` when `PREDICTION_LIFECYCLE_CBB_CANONICAL_PIPELINE=true`
- Generate tournament forecast at `10:15`
- Recalculate tournament outlook at `10:25`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `12:00` and `17:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run CBB EPA lifecycle jobs via `scheduleEpaLifecycle()`

### WCBB

- Active October–April, with ending-year seasons, inline daily scoreboard imports, and the same five-minute pregame coverage check.

- Sync all team schedules weekly on Sunday at `01:45`
- Sync teams daily at `02:45`
- Sync game details daily at `03:15`
- Daily current-week sync at `03:00`
- Live scoreboard sync every 5 minutes between `12:00` and `01:00`
- Game details sync every 30 minutes between `14:00` and `02:00`
- Grade predictions at `03:30`
- Evaluate immutable canonical predictions at `03:35` when `PREDICTION_LIFECYCLE_WCBB_CANONICAL_PIPELINE=true`
- Calculate Elo at `04:00`
- Calculate team metrics at `04:30`
- Generate predictions at `05:00`
- Generate canonical predictions at `05:05` when `PREDICTION_LIFECYCLE_WCBB_CANONICAL_PIPELINE=true`
- Generate tournament forecast at `05:15`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run WCBB EPA lifecycle jobs via `scheduleEpaLifecycle()`

### MLB

The shared MLB scheduler guard runs March through October. In November it requires a stored postseason game within the next three days or the previous two days, including today, so postseason sync and final grading can finish. Manual commands remain available throughout the year.

- Daily schedule sync at `01:30`
- Live scoreboard sync every 5 minutes between `13:00` and `04:00`
- Game details sync every 30 minutes between `16:00` and `04:00`
- Reconcile the previous day's scoreboard at `04:05`
- Repair missing inning line scores at `04:10`
- Reconcile missing final scores from team-stat runs at `04:20`; the command is
  idempotent, runs on one server, and never overwrites score conflicts
- Grade predictions at `04:30`
- Evaluate immutable canonical predictions at `04:55` when `PREDICTION_LIFECYCLE_MLB_CANONICAL_PIPELINE=true`
- Reconcile and grade immutable rotation-starter forecasts at `04:35`; only
  forecasts captured before scheduled first pitch count toward accuracy,
  confidence calibration, Brier score, log loss, and pitcher-rating error
- Calculate Elo at `05:00`
- Recalculate team metrics at `05:30`, after final-score reconciliation
- Generate predictions at `06:00`
- Generate canonical predictions at `06:05` when `PREDICTION_LIFECYCLE_MLB_CANONICAL_PIPELINE=true`
- Run the initial private tabular shadow pass at `06:10`
- Run the initial private F3/F5 shadow pass at `06:12`
- Record initial private shadow decisions at `06:15`
- Train and register a weekly challenger Monday at `06:40` Central Time
- Train and register the weekly F3/F5 challenger Monday at `07:20` Central Time
- Grade pick candidates at `04:40` and settle model decisions at `04:50`
- Generate playoff forecast at `08:35`
- Sync odds every 4 hours between `08:00` and `23:00`
- After the `08:00`, `12:00`, `16:00`, and `20:00` odds cycles, refresh
  baseline predictions at `:30`, daily picks at `:35`, tabular shadow outputs at
  `:50`, F3/F5 shadow outputs at `:55`, and immutable decisions at `:58`
- Generate daily picks hourly at `:20` between `08:00` and `23:30`
- Sync player props twice daily at `11:00` and `16:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Refresh probable pitchers (next 48h scoreboard) every 30 minutes between `06:00` and `23:00`; preserves previously supplied ESPN probables, projects missing starters from the latest rotation and intervening schedule, and regenerates predictions whenever the resolved starter or source confidence changes

### WNBA

- Daily current-week sync at `01:00`
- Live scoreboard sync every 5 minutes between `19:00` and `23:00`
- Grade predictions at `00:00`
- Evaluate immutable canonical predictions at `00:05` when `PREDICTION_LIFECYCLE_WNBA_CANONICAL_PIPELINE=true`
- Calculate Elo at `00:30`
- Calculate team metrics at `01:30`
- Generate predictions at `02:00`
- Generate canonical predictions at `02:05` when `PREDICTION_LIFECYCLE_WNBA_CANONICAL_PIPELINE=true`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`

### NFL

- Optional frozen prop experiment (`NFL_PROP_SHADOW_ENABLED=true`): snapshot stored quotes at `:15` and `:45` between `08:00` and `23:00` Central, grade at `08:45` daily, and report at `08:55` Monday. Ships with the frozen, hash-pinned seven-market bundle from October 23; `NFL_PROP_SHADOW_BUNDLE_PATH` and `NFL_PROP_SHADOW_BUNDLE_SHA256` can override it together. Captures the first eligible observation within 24 hours of kickoff per player/game/market/bundle; stale, future and in-play observations are excluded. Uses existing snapshot/evaluation tables, never refreshes providers, never retrains or promotes models, and never changes production picks. Missing actuals remain pending; grading preserves the frozen quote and first recorded result.

- Baseline NFL forecasts and their feature snapshots publish in one transaction. Optional `EvaluateShadowPrediction` jobs dispatch after commit on the application queue, with one attempt and a 120-second timeout. A shadow failure cannot roll back the baseline; late shadow jobs cannot overwrite newer forecasts or refresh the baseline timestamp. Shadow observations retain their own records without modifying frozen snapshot inputs or outputs.

- At `07:45` Central during the depth-chart season, `espn:sync-nfl-depth-charts --season=<current> --sync` refreshes all teams daily and waits for completion before reporting success. Each fetch preserves a timestamped observation, allowing recent QB-change comparisons and fresh personnel inputs for the later prediction pipeline. Game links remain tied to the prediction revision that selected the chart.

- At `08:00` Central in season, `nfl:sync-matchup-rosters --season=<current>` validates and archives the free roster release, then refreshes ESPN-to-GSIS mappings. It requires all 32 teams and rejects conflicting quarterback identities before importing.
- At `08:05` Central in season, `nfl:sync-nflverse-pbp --season=<current>` refreshes the free public play-by-play release, archives the source, and fails its heartbeat if completed games lack play coverage. This supplies the independent matchup analysis before daytime forecasts.
- At `08:13` Central in season, `nfl:sync-matchup-tracking --season=<current>` imports weekly QB release times from the aggregate NGS passing feed and PFR player missed/combined tackles plus rushing yards before/after contact and carries via nflverse. It excludes season-total rows, validates game/team/player keys, and publishes all three datasets atomically; unresolved source game rows are reported as failures.
- At `08:14` Central in season, `nfl:sync-matchup-win-rates --season=<current>` retrieves the configured ESPN team win-rate article. It validates all 32 teams, percentages, published ranks and the dated covered week, archives the source, and retains immutable snapshots. Matchups require the selected baseline week, publication and first observation before cutoff, a publication age of at most 14 days and at least two prior games per team. Configure each new season’s official article in `nfl.matchup_win_rate_sources`; an unknown season fails instead of reusing last season. No AI requests are used.
- At `08:11` Central in season, `nfl:sync-matchup-pressure --season=<current>` imports free PFR weekly pressure counts via nflverse after roster and play-by-play refreshes. Game/opponent/player identities must match; matchup evaluation normalizes pressure counts to play-by-play dropbacks and requires matching sacks. Missing player rows remain ineligible, never zero pressure.
- `nfl:sync-matchup-participation --season=<completed>` is a historical import, not a current-season scheduled substitute. It joins FTN postseason coverage, pressure flags, exact rusher counts and eleven-player personnel lists to verified game/play/team identities. Incomplete personnel stays unknown, and the command rejects the current year.
- At `08:10` Central in season, `nfl:sync-matchup-charting --season=<current>` joins the public FTN release to existing play identities after the play-by-play refresh. It archives the source, preserves unknown charting as missing, and fails on unmatched plays or insufficient scrimmage coverage.
- At `08:12` Central in season, `nfl:sync-matchup-schedule --season=<current>` verifies completed regular-season games from the current and prior three seasons against public schedule identities and scores. It appends separate retrospective rest, division, overtime, official international-schedule, validated roof/venue and closing-line evidence for matchup records; it never rewrites games, odds snapshots or predictions.
- At `08:15` Central in season, `nfl:sync-game-quarterbacks --season=<current> --apply` fills missing final-game starter identities from the public nflverse schedule. It validates event/team/date/score identity, preserves existing conflicting values and all forecasts, records source provenance, and fails its heartbeat when source coverage is incomplete. Without `--apply`, the command is read-only; `--file` accepts a reviewed source archive.

- Daily current-week sync at `08:00`
- Live scoreboard sync every 5 minutes between `17:00` and `02:00`
- Game details sync every 30 minutes between `06:00` and `02:00`, checking a 14-day lookback for missing details or plays that have not reached the final score.
- The ordered NFL pregame pipeline and registry predict/full generation stage now use canonical generation/readiness followed by bounded research; neither invokes legacy generation. Native snapshot capture runs the stored-data true-EPA preflight and non-persisting deterministic recommendation calculation. Release `1.2.0` (`nfl-pregame-v3`) must be registered before generation. Readiness verifies fresh canonical input/recommendation evidence and exact-revision tracking decisions, including all existing EPA, quarantine, market, and candidate holds. Unchanged fresh inputs may reuse immutable revisions. Legacy snapshot creation is no longer a completeness requirement. The research market expiry remains 36 hours by default; provider refresh timing is unchanged.
- Grade predictions at `08:30`
- Evaluate immutable canonical predictions at `08:35` when `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true`
- Audit frozen NFL spread history at `08:50` with `nfl:validate-spread-models`; record per-version sample shortages and observed performance in `nfl-spread-validation.log`. Successful execution means the audit ran, not that the model is approved. No odds fetch or automatic promotion occurs.
- Calculate Elo at `09:00`
- Calculate team metrics at `09:30`
- Generate predictions at `10:00`
- NFL season-evidence weighting is recalculated inside every historical-Elo forecast (including the pregame pipeline), using completed current-season regular games and covered metric samples. No separate tuning job is required; byes do not advance the evidence weight.
- Generate canonical predictions at `10:05` when `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true`
- Settle model decisions at `08:45`
- Record private shadow decisions at `10:15`
- Train and register a weekly challenger Tuesday at `12:40` Central Time,
  after the `11:35` readiness pass
- Sync game odds only at `06:10`, `10:10`, `14:10`, `18:10`, and `22:10` Central. NFL responses are reused for four hours, with a per-request lock to coalesce concurrent callers. Confirmed provider credit exhaustion pauses uncached requests across the account for 24 hours; cached data remains usable.
- Sync player props twice daily at `10:00` and `15:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run NFL EPA lifecycle jobs via `scheduleEpaLifecycle()`

### CFB

- Daily at `03:40`, after canonical evaluation: `cfb:review-model-candidates` writes the read-only, release-separated paired candidate report to `storage/logs/cfb-model-candidates.log`. It compares only timestamp-safe quotes/history; reports missing paired coverage; preserves source prediction and grading revisions; and never promotes candidates. Release `1.8.0` freezes opponent-adjusted scoring, result-rating, and fixed 50/50 market-blend comparisons alongside the unchanged independent markets for prospective tracking.

- Daily at `03:25` Central: sync the current week. Live scoreboards refresh every five minutes between `10:00` and `02:00`; final-game details refresh every thirty minutes between `14:00` and `02:00`.
- Grade legacy predictions at `03:00`; canonical evaluation and decision settlement run at `03:05` and `03:10` when canonical generation is enabled.
- Import FPI at `03:45`. Standalone legacy Elo (`03:30`), metrics (`04:00`), and prediction (`04:30`) jobs are disabled when the canonical pipeline owns those stages.
- Daily at `04:35` and hourly at `:45`, `08:00`–`23:00`: run the canonical pregame pipeline across the next eight days, so the full weekly board follows shared metric refreshes. The pipeline repairs and normalizes source history, recalculates Elo and metrics, then refreshes markets and forecasts in kickoff order. Metrics batches report individual refreshed/failed teams and continue through the slate; games touching failed required teams remain blocked, while independently refreshed games proceed through the unchanged release and approval checks. `--week` scopes manual recovery to a season week. Explicit non-FBS-only games are outside its forecast scope. Required source/integrity failures remain visible; unavailable odds and weather do not prevent an unpriced forecast. Optional weather refreshes follow publication, and a single-game recovery requests weather only for that game.
- Before ratings, odds matching, and forecast generation, the pipeline verifies each upcoming FBS game’s official summary kickoff and venue, synchronizes the canonical event, then reapplies its horizon. Unverified metadata holds only the affected game. `espn:sync-cfb-pregame-metadata` provides standalone recovery without fetching box scores or plays.
- Completed history normalization is reused for unchanged accepted source revisions and the same prior-season EPA baseline revision. A newly trained EPA baseline invalidates normalization reuse before metrics and forecast generation. EPA updates write only existing accepted play rows in bounded batches. Historical game and Elo reads omit large odds payloads.
- CFBD weighted EPA is optional enrichment. An explicit paid-subscription denial records WEPA as unavailable with null values; supported metric refreshes continue. Invalid credentials and exhausted transient provider failures still preserve prior metrics and fail the affected refresh. CFBD GETs make at most three attempts (250 ms and 1,000 ms backoff) for connection failures, 429, and 500/502/503/504; authentication and other permanent errors are not retried. An exhausted source is cached as failed within a metrics process to avoid repeating its request budget for every team in that batch.
- Daily/hourly CFB pregame refreshes dispatch with `--queued` to the existing bulk `sync` worker. A unique season lock prevents duplicate queued refreshes. Dispatch does not record a success heartbeat or external success ping. Optional weather refreshes are limited to the validated future game selection, including scoped `--games` recovery. Terminal worker outcomes persist in `command_heartbeats` with source `worker`, a run UUID, exit state, per-game results, selected/pending/held/failed counts, actual publication counts, and separate reused counts. Partial/failed workers are marked failed in the queue and record a failure heartbeat; external scheduled heartbeat pings occur only on full worker completion. `cfb:pregame:worker-status` retains the latest running/complete/partial/failed outcome. Per-run progress caches preserve completed publications if a worker is interrupted. The queue still has one attempt; bounded transient HTTP retries do not replay the entire job. Sequential isolated forecast processes retain their 192 MB cap and have a 300-second generation budget (below the 600-second evidence lock and 1,700-second worker limit), so a cold evidence build is not killed at the former 120-second cutoff. Resume hashes include failed input cohorts so cached completed stages cannot bypass new holds. Football-signal evidence reads only required prediction columns and pages by `(generated_at, id)` instead of offsets; older revisions are skipped only after existing frozen-evidence checks accept a game, preserving fallback for rejected revisions.
- Daily at `04:50`: the CFB operations sentinel validates health. Every hour at `:55`, `healthcheck:validate-data --sport=cfb --if-stale=120 --skip-ai-summary --skip-alerts` retries when no audit has completed in two hours. Each finding is saved as its check finishes; abandoned runs older than two hours are marked interrupted on retry.
- Sync odds every four hours and injuries every thirty minutes between `08:00` and `23:00`.
- CFB injury workers and the pregame pipeline also run `cfb:sync-official-availability`. Public SEC, Big Ten and ACC conference widgets feed immutable game/team report revisions with source publication and observation times. Matching uses both opponents and the conference-time kickoff date, with game-specific injury scope in the application timezone; unlinked absences and exemptions remain partial coverage. Reports older than 36 hours remain stale, not healthy. Official absences and source-verified corrections are preserved across empty ESPN refreshes. Forecasts retain unknown/stale/partial coverage and QB-conflict flags rather than interpreting zero ESPN rows as a healthy roster. Unavailable conferences (including the Pac-12 public page until a working feed is published) and nonconference games without a report remain explicitly unknown; known source-linked corrections still apply.
- Canonical health checks inspect published forecasts with markets, flag forecasts older than 24 hours separately from missing forecasts, and compare forecast generation time with actual team-metric revisions. Final-game health requires complete source stats rather than perpetual game-row updates. Distant scheduled games are not subject to the twelve-hour near-kickoff row freshness rule.

## Maintenance And Non-Sport Jobs

- Prune failed jobs daily at `03:20`
- Prune command heartbeats daily at `03:25` using the model's configured retention window
- Send daily digests every minute via `alerts:send-daily-digests`
- Send the admin email report daily at its configured time (default `07:30`)

## Operational Notes

- Every scheduled event is named and uses `onOneServer()` so Laravel Cloud's
  shared scheduler lock prevents duplicate execution across replicas
- Every scheduled event also uses `withoutOverlapping()` and `runInBackground()`
- Scheduler lock coordination requires all replicas to use the same shared cache;
  Laravel Cloud should use the shared Valkey store for cache and scheduler locks
- Heartbeat success and failure tracking is attached to scheduled jobs through `CommandHeartbeatService`
- Live scoreboard jobs resolve command arguments dynamically for the current date
- Queue workers are still required for queued work kicked off by scheduled commands

## How To Inspect Current Schedule

Use:

```bash
php artisan schedule:list
```

For implementation details, review:

```bash
routes/console.php
```

The CFB pregame pipeline now requires a successful daily FPI import and metric recalculation before injuries, odds, and canonical generation. Empty required imports stop generation and retry on the next run. New snapshots record individual rating provenance; missing opponent ratings remain an explicit spread hold.


### NFL prospective prop probability evaluation

`nfl:prop-probability-experiment capture --season=YEAR` runs at :25 and :55 from 08:00–23:59 in the existing scheduler timezone, after the :20 pregame pipeline when it runs. It captures only scheduled kickoffs within 24 hours using fresh stored quotes, without provider refreshes or publishing recommendations. The first forecast for each code-version/player/game/market is immutable. `NFL_PROP_PROBABILITY_EXPERIMENT_ENABLED` defaults true and controls these scheduled jobs. Capture uses a single-server overlap lock and heartbeat. Grading runs at 09:05 after ingest; missing actuals stay pending. The daily 09:15 report writes per-market, per-code-version Brier/log-loss and pending counts to `storage/logs/nfl-prop-probabilities.log`. No queue worker or paid AI call is needed. Deployment and future completed outcomes are required for prospective evidence.

### NFL prospective matchup evidence

`nfl:materialize-signal-observations --capture-evidence --season=<fall season> --limit=16` runs at :35 during 06:00–23:59 America/Chicago in season, with one-server and overlap locks plus the existing command heartbeat. It follows the :20 stored prediction runs and freezes at most an early and late capture per game in the next 24 hours. Early captures occur more than 90 minutes before kickoff; late captures occur within 90 minutes. Each invocation creates at most sixteen captures, reading existing sources only; it never refreshes providers or calls AI. The existing :05/:20/:35/:50 grader grades the same observation ledger; newly captured records can be picked up at :50. Snapshot materialization/grading can be repeated safely. `nfl:report-signal-grades --reconcile --season=<season>` diagnoses gaps without mutation. Reconciliation selects verified frozen canonical recommendation inputs when either NFL canonical switch is enabled, preserves frozen-kickoff and quote-time diagnostics, and separates native forecast identity from historical observation/grade coverage. Capture now uses those canonical inputs under the distinct `nfl-evidence-canonical-v1` policy, requiring the frozen source marker and full runtime configuration hash. Older inputs without that contract stay held until future scheduled generation supplies it. Apply the native evidence-lineage migration before running this code. Native observations retain explicit canonical identity and no legacy prediction ID; grading and latest-row reports separate sources. Use `--forecast-source=legacy` for historical reports. Both switches off preserve historical capture. Preregistered historical evidence trials record shadow outputs only for the old policy; native captures remain tracking-only with no trial outputs or approvals. The cadence, limits and heartbeat are unchanged; report evaluation is read-only and never promotes a model.


## NFL admin monitor report snapshots

`nfl:refresh-model-monitor --limit=3` checks pending scopes every minute Central, refreshing each saved scope and the shared legacy report at most once every fifteen minutes, during the NFL schedule window. Outside that window it queues a refresh only when an admin requested a report in the preceding thirty minutes, so historical reports remain available on demand. One-server and overlap locks prevent concurrent builders. The command builds the default dashboard and up to two additional recently requested scopes, plus one shared five-category legacy signal report. A bounded registry holds sixteen requested scopes. Report values are atomically replaced only after successful generation and retained without a short expiry; failures keep last-good results and fail the command heartbeat. Reports older than thirty minutes are visibly stale. HTTP requests never calculate reports or dispatch synchronous jobs; cache misses show preparation status and register the scope for the next scheduled build. Refresh only reads a newer saved result. Use `--force` for an explicit operator rebuild. Cache eviction/clear requires the next scheduled build or an explicit operator refresh; it never triggers request-time recomputation.

`nfl:calibrate-player-props --season=YEAR --through=YYYY-MM-DD --validation-from=YYYY-MM-DD` is a manual read-only retrospective replay and fixed chronological logistic calibration report. It compares neutral legacy, recent-five and matchup projections, and evaluates reliability and returns at saved pregame prices. It never enables calibrated probabilities in production. Source revisions and historical injury states are not point-in-time verified.

CBB cutover preparation: `cbb:generate-canonical-predictions` defaults to an eight-day rolling horizon (`--days-forward`), while explicit `--game` and `--date` targets retain their scope. Placeholder matchups are reported as holds and skipped without publishing. Existing registry ownership, native pipeline gates, ending-year seasons, schedules, grading and approval rules remain unchanged. NBA/CBB readiness also checks that switching reads cannot discard an in-flight legacy baseline.

MLB offseason cutover uses the existing canonical generation/evaluation schedules and finalization listener. The explicit `mlb:reconcile-stale-games` repair command is manual, bounded and dry-run by default; applied verified finals queue existing grading after commit, while unresolved holds never finalize. No retrospective native pregame forecasts are generated for the completed season. The next real slate must pass normal coverage checks; offseason empty-slate readiness is explicitly requested with `--offseason`.

WCBB canonical cutover preparation retains the existing season guard, 03:35 canonical evaluation and 05:05 canonical generation schedules, after the existing Elo/metric steps. The CBB/WCBB shared canonical command defaults to an eight-day horizon, keeps explicit game/date scopes, and reports placeholder holds. WCBB release `1.1.1` enables fallback to usable previous-season efficiency and tempo when the current row is unusable. WCBB reports unusable rows without a usable fallback as intentional input holds under the full-model release; it does not publish those games or change existing approval rules. No production switch is changed by this preparation. Register the explicit `1.1.0` release only during an authorized rollout, with its real effective time; never create retrospective pregame forecasts for games that have already started.

WCBB live cutover compatibility retains only existing pinned live baselines observed before the effective full-model release, for games already underway at that release. No cadence or live formula changes: the existing live updater continues from those frozen snapshots, while canonical pregame generation and grading keep their ownership. Compatibility expires at final status and never repairs missing inputs or creates retrospective pregame forecasts. Unverified live baselines still block activation.

WNBA offseason cutover preparation leaves the April–October season guard, daily canonical evaluation at 00:05 and canonical generation at 02:05 unchanged. The normal metric step remains at 01:30 before generation. Both native schedules still require the pipeline switch; enabling a switch does not force offseason generation or fabricate a slate. The existing queue-backed game-details job now verifies and updates summary state as well as stats, with event/team identity and final-score guards. A stale-record rollout can target the existing `espn:sync-wnba-game-details <eventId> --sync` command; inspect its outcome and remaining readiness holds before activation. Neither scheduler dispatch nor missing provider evidence proves finalization.

The post-cutover shared cleanup removes empty service adapters and the unused simpler NFL writer, without changing any schedule or season guard. WCBB's temporary pre-cutover live bridge is retired only after production confirmed all seven protected games final and no remaining bridge consumers. Missing native inputs continue to hold; schedule aliases continue to route to canonical writers when both switches select canonical. Historical reconstruction and grading paths remain available.

Canonical command consolidation leaves all seven sports' scheduled command names, options, cadence, season guards, worker/heartbeat behavior and pipeline ownership unchanged. Basketball evaluation and readiness wrappers now use the same existing shared handlers as football/baseball. Finalization/missing-score guards and per-game failure continuation remain intact; readiness reports preserve unresolved/input/approval holds and never publish forecasts or rewrite historical results.
