# Scheduler Reference

This document replaces the old sport-specific automation docs.

The source of truth for scheduled jobs is:
- `routes/console.php`

If this document and `routes/console.php` ever disagree, trust `routes/console.php`.

## Overview

The app uses Laravel's scheduler for:
- sport data sync pipelines
- prediction generation pipelines
- odds, injuries, futures, and player prop refreshes
- maintenance jobs
- alert digest delivery

Most sport jobs are season-gated in `routes/console.php` so they only run during relevant months.

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

- Daily scoreboard sync at `01:00`
- Live scoreboard sync every 5 minutes between `18:00` and `03:00`
- Game details sync every 30 minutes between `18:00` and `03:00`
- Grade predictions at `03:30`
- Evaluate immutable canonical predictions at `03:35` when `PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE=true`
- Calculate Elo at `04:00`
- Calculate team metrics at `04:30`
- Generate predictions at `05:00`
- Generate canonical predictions at `05:05` when `PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE=true`
- Generate playoff forecast at `05:15`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `10:00` and `14:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run NBA EPA lifecycle jobs via `scheduleEpaLifecycle()`

### CBB

- Sync tournament structure daily at `01:00`
- Sync teams weekly on Sunday at `01:15`
- Sync all team schedules weekly on Sunday at `01:30`
- Sync players weekly on Sunday at `02:15`
- Backfill stale games daily at `03:00`
- Daily current-week sync at `02:00`
- Live scoreboard sync every 5 minutes between `12:00` and `01:00`
- Game details sync every 30 minutes between `14:00` and `02:00`
- Grade predictions at `05:00`
- Evaluate immutable canonical predictions at `05:05` when `PREDICTION_LIFECYCLE_CBB_CANONICAL_PIPELINE=true`
- Calculate Elo at `05:30`
- Calculate team metrics at `06:00`
- Generate predictions at `06:30`
- Generate canonical predictions at `06:35` when `PREDICTION_LIFECYCLE_CBB_CANONICAL_PIPELINE=true`
- Generate tournament forecast at `07:00`
- Recalculate tournament outlook at `07:15`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `12:00` and `17:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run CBB EPA lifecycle jobs via `scheduleEpaLifecycle()`

### WCBB

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

- Daily current-week sync at `08:00`
- Live scoreboard sync every 5 minutes between `17:00` and `02:00`
- Game details sync every 30 minutes between `17:00` and `02:00`
- Grade predictions at `08:30`
- Evaluate immutable canonical predictions at `08:35` when `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true`
- Calculate Elo at `09:00`
- Calculate team metrics at `09:30`
- Generate predictions at `10:00`
- Generate canonical predictions at `10:05` when `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true`
- Settle model decisions at `08:45`
- Record private shadow decisions at `10:15`
- Train and register a weekly challenger Tuesday at `12:40` Central Time,
  after the `11:35` readiness pass
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `10:00` and `15:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run NFL EPA lifecycle jobs via `scheduleEpaLifecycle()`

### CFB

- Daily current-week sync at `07:00`
- Live scoreboard sync every 5 minutes between `12:00` and `02:00`
- Game details sync every 30 minutes between `14:00` and `02:00`
- Grade predictions at `03:00`
- Evaluate immutable canonical predictions at `03:05` when `PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE=true`
- Calculate Elo at `03:30`
- Import FPI at `03:45`
- Calculate team metrics at `04:00`
- Generate predictions at `04:30`
- Generate canonical predictions at `04:35` when `PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE=true`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`

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
