# Prediction review execution

The shared review path is opt-in. `prediction_review.enabled` and a per-sport switch must both be enabled. Defaults leave all sports disabled and do not change legacy NFL scheduling, public approval, model calculation, or production configuration.

## Work and result identities

`PredictionReviewCoordinator::reconcileGame` reads the selected forecast through the existing review input builder and records one durable `AnalysisWorkItem` per sport/game/stage. The desired fingerprint combines semantic forecast/decision input, research evidence, and provider/model/prompt/schema/policy versions. It normalizes equivalent stored season-type representations and excludes read/check timestamps while retaining actual evidence observation/expiry and applicability.

Input changes atomically replace the desired fingerprint and release an old lease. Work states describe processing (`pending`, `queued`, `running`, `deferred`, `succeeded`, `failed`, `cancelled`), never betting approval. Dispatch leases keep repeated small batches from repeatedly selecting queued work; stale queue/running leases are recoverable. Missing events do not create invented event records; nullable event linkage retains explicit game and forecast table identities. Withdrawal or the end of the pregame window cancels processing without falling back to a legacy forecast.

`PredictionReviewRunner` claims a bounded lease and re-reads current forecast/research before provider admission and before saving. One normal review uses `PredictionReviewAgent`; it does not invoke the old four supporting AI assessments. `PredictionReviewDecisionPolicy` caps structured output at existing deterministic eligibility and preserves the original stored selection and price. NFL delegates to its compatibility policy.

Every valid result creates an immutable `PredictionReviewRevision`. A response arriving after the input/lease changed is retained as `superseded`, never made current. Presentation must compare current fingerprints, applicability, and expiry and recheck deterministic eligibility. Raw review prose is interpretation and cannot establish approval.

## Accounting and retries

Every paid new review request reserves estimated cost in `ai_generations` before calling the provider and records usage for valid and malformed responses. Timeouts and uncertain usage retain conservative reservations; they are never zero cost. The same uncertain request is not immediately purchased again. A validated completed response recorded before a worker persistence crash can be recovered from its generation record without another provider call.

The new admission guard serializes review reservations and checks rolling application, sport, and event estimated usage plus event attempt limits. It reserves late pregame attempts, respects the shared provider circuit, and uses delayed bounded retries with jitter. Admission/budget/cooldown deferrals do not count as paid processing attempts. No batch-wide sleeping is used.

Application usage includes recorded existing AI generations and recognizes older `nfl_game` research contexts. Existing NFL research and other old purposes retain their own admission services. These controls therefore govern new review admission against the observed ledger; they are not a provider-account billing hard cap or a new atomic enforcement policy for every legacy purpose. Missing pricing keeps reservations visible rather than claiming measured cost.

`retryGame` is for authorized operational callers. It resets failed/deferred work while retaining active running leases and current completed results. It never clears the provider circuit, erases prior generations, resets spend, or bypasses the admission guard. HTTP authorization must be enforced before calling it and checked again by queued retry work.

## Commands

Automatic wiring lives in `config/game_analysis.php`, independently of the capability flags. `GAME_ANALYSIS_SCHEDULE_ENABLED` and `GAME_ANALYSIS_PUBLISHER_ENABLED` both default false. The first enables bounded Central-time reconciliation/queue-dispatch schedules; the second queues shared analysis only after successful canonical publication commits. Neither changes a model release or an existing NFL research switch.

`sports:reconcile-game-analysis` rotates source/review refresh dispatch across eligible future games. Both reconciliation commands use authoritative canonical event starts for the horizon, with legacy game date/time only when event timing is absent. Normal per-item execution uses `RefreshGameAnalysis`; authorized retries carry the requester and recheck current admin/disclosure access before writing. Explicit admin work is not silently deduplicated by an already-queued automatic refresh. Durable work leases and admission records still prevent duplicate provider purchases.

Enabling shared review ownership for a sport transfers its daily-review writer and saved legacy AI-read ownership to the new path. The existing model forecast and approval records remain authoritative. Do not enable the new flags until additive schema and workers have been verified. Disabling the global review gate stops new execution and leaves recorded work intact.

`sports:report-game-analysis --sport=nba` is a strictly read-only operational report. It shows recorded work states, overdue unfinished work, the oldest unfinished creation time, unexpired saved revisions, source registration/failure counts, and 24-hour known versus unknown shared-review usage. It does not rebuild forecasts, fetch sources, call providers, or claim that every saved revision is still applicable. Current presentation performs the required input and approval checks separately.

The reconciliation and execution commands below can write work, enqueue jobs, or call a paid provider; use a verified environment and explicit activation. Reading the API never invokes them.

```bash
php artisan sports:reconcile-prediction-reviews --sport=nba --date=2026-11-20 --days-forward=2 --dry-run
php artisan sports:run-prediction-reviews --sport=nba --dry-run
```

Reconciliation is read-only with `--dry-run`; otherwise it writes desired work but does not invoke AI. It supports sport, season, business-date window, and bounded inspection filters. Running work normally dispatches bounded unique queue jobs. `--inline` invokes the runner synchronously and may call the provider. The command rotates sport priority by recent attempts, applies per-sport quotas, prioritizes never-attempted and older work, and reports coverage separately from dispatch/completion. A failed/deferred inline item yields failure rather than implying full success. Expired pregame work is cancelled on a non-dry run.

The shared application orchestration command/job may compose research and review; it should pass the strict read-only `GameResearchService::forGame` result into reconciliation. Do not activate independent competing owners of the same paid review stage.

## Deployment and rollback

Apply `2026_11_20_230100_create_prediction_review_work_tables` before activating the new path or workers. This migration adds tables only. Deploy compatible read paths and queue workers before enabling a sport. No existing migration, prediction table, model release, calibration gate, or public bet approval is repurposed.

Environment names include `PREDICTION_REVIEW_ENABLED`, `PREDICTION_REVIEW_<SPORT>_ENABLED`, `PREDICTION_REVIEW_PROVIDER`, and `PREDICTION_REVIEW_MODEL`. Review default budgets/deadlines/capacity in `config/prediction_review.php` before any activation. Do not enable the new path merely to inspect an existing game.

To stop new review purchases, disable global or sport admission. Preserve work, immutable revisions, generation records, and reservations. Revert presentation only to a compatible current forecast path; never revive a retired writer or expose a stale review as current.

## Local validation

Use SQLite in memory and faked providers. The focused suite covers idempotency, exact approval quote preservation, immutable results, execution/freshness invalidation, budget deferral, malformed paid output, abandoned leases, forecast withdrawal, fair sport admission, unknown usage, persistence-crash recovery, and explicit operational retry.

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' CACHE_STORE=array QUEUE_CONNECTION=sync \
  bash scripts/php85.sh artisan test --compact tests/Feature/Predictions/PredictionReviewExecutionTest.php tests/Architecture/MigrationSafetyTest.php
```
