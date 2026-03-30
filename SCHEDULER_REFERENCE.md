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
- Calculate Elo at `04:00`
- Calculate team metrics at `04:30`
- Generate predictions at `05:00`
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
- Calculate Elo at `05:30`
- Calculate team metrics at `06:00`
- Generate predictions at `06:30`
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
- Calculate Elo at `04:00`
- Calculate team metrics at `04:30`
- Generate predictions at `05:00`
- Generate tournament forecast at `05:15`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`
- Run WCBB EPA lifecycle jobs via `scheduleEpaLifecycle()`

### MLB

- Daily schedule sync at `01:30`
- Live scoreboard sync every 5 minutes between `13:00` and `04:00`
- Game details sync every 30 minutes between `16:00` and `04:00`
- Grade predictions at `04:30`
- Calculate Elo at `05:00`
- Calculate team metrics at `05:30`
- Generate predictions at `06:00`
- Generate playoff forecast at `06:15`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync player props twice daily at `11:00` and `16:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`
- Sync futures odds every 4 hours between `08:00` and `23:00`

### WNBA

- Daily current-week sync at `01:00`
- Live scoreboard sync every 5 minutes between `19:00` and `23:00`
- Grade predictions at `00:00`
- Calculate Elo at `00:30`
- Calculate team metrics at `01:30`
- Generate predictions at `02:00`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`

### NFL

- Daily current-week sync at `08:00`
- Live scoreboard sync every 5 minutes between `17:00` and `02:00`
- Game details sync every 30 minutes between `17:00` and `02:00`
- Grade predictions at `08:30`
- Calculate Elo at `09:00`
- Calculate team metrics at `09:30`
- Generate predictions at `10:00`
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
- Calculate Elo at `03:30`
- Import FPI at `03:45`
- Calculate team metrics at `04:00`
- Generate predictions at `04:30`
- Sync odds every 4 hours between `08:00` and `23:00`
- Sync injuries every 30 minutes between `08:00` and `23:00`

## Maintenance And Non-Sport Jobs

- Prune failed jobs daily at `03:20`
- Send daily digests every minute via `alerts:send-daily-digests`

## Operational Notes

- Most scheduled jobs use `withoutOverlapping()` and `runInBackground()`
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
