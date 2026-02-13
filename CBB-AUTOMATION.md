# CBB Automated Pipeline

## Overview

The CBB data pipeline is fully automated using Laravel's task scheduler. It runs daily to sync games, calculate analytics, and generate predictions.

## Schedule

All tasks run automatically - **no manual intervention needed** during the season.

### Daily Tasks

| Time | Task | Description |
|------|------|-------------|
| **2:00 AM** | Sync Current Week | Fetches current week's games (teams + games) |
| **5:00 AM** | Calculate Elo | Updates Elo ratings for all completed games |
| **5:30 AM** | Calculate Metrics | Calculates team offensive/defensive ratings, Four Factors |
| **6:00 AM** | Generate Predictions | Creates predictions for all upcoming games |

## How It Works

### 1. Sync Current Week (2:00 AM)
```bash
php artisan espn:sync-cbb-current
```
- Syncs teams if not already synced
- Fetches current week's games
- Uses ESPN's week-based API structure

### 2. Calculate Elo Ratings (5:00 AM)
```bash
php artisan cbb:calculate-elo --season=2026
```
- Processes all completed games in chronological order
- Skips games that already have Elo history (idempotent)
- Updates team Elo ratings + saves history
- Uses: home court advantage (70 pts), margin of victory, NCAA Tournament multiplier

### 3. Calculate Team Metrics (5:30 AM)
```bash
php artisan cbb:calculate-team-metrics --season=2026
```
- Calculates for all teams with completed games
- Updates existing metrics (idempotent)
- Metrics: Offensive/Defensive Rating, Net Rating, Pace, Four Factors (eFG%, TOV%, ORB%, FTR, TS%)

### 4. Generate Predictions (6:00 AM)
```bash
php artisan cbb:generate-predictions --season=2026
```
- Finds all upcoming games (`status != STATUS_FINAL`)
- Uses current Elo ratings + team metrics
- Updates existing predictions (idempotent)
- Outputs: spread, total, win probability, confidence score

## Running the Scheduler

### Production (Required)

Add this to your system crontab to run the scheduler every minute:

```bash
* * * * * cd /path/to/picksports && php artisan schedule:run >> /dev/null 2>&1
```

### Development (Laravel Herd)

Laravel Herd automatically runs the scheduler - no setup needed!

### Manual Testing

Test the scheduler without waiting:

```bash
# Run all due tasks now
php artisan schedule:run

# View scheduled tasks
php artisan schedule:list

# Test a specific task
php artisan schedule:test
```

## Queue Workers

**IMPORTANT:** The scheduler queues jobs - you need a queue worker running:

### Production
```bash
# Run as a daemon (use supervisor or systemd)
php artisan queue:work --tries=3 --timeout=180

# Or use Laravel Horizon for advanced queue management
```

### Development
```bash
# Run in background during development
php artisan queue:work --stop-when-empty
```

## Idempotency

All commands are **idempotent** - safe to run multiple times:

- **Elo calculation**: Skips games with existing history (unless `--reset`)
- **Team metrics**: Updates existing records via `updateOrCreate`
- **Predictions**: Updates existing predictions via `updateOrCreate`
- **Current week sync**: Updates existing games, adds new ones

## Manual Overrides

You can still run commands manually anytime:

```bash
# Sync full season (one-time)
for week in {1..20}; do php artisan espn:sync-cbb-games 2026 $week 2; done

# Sync teams
php artisan espn:sync-cbb-teams

# Recalculate everything from scratch
php artisan cbb:calculate-elo --season=2026 --reset
php artisan cbb:calculate-team-metrics --season=2026
php artisan cbb:generate-predictions --season=2026

# Sync specific week
php artisan espn:sync-cbb-games 2026 1 2
```

## Monitoring

### Check Scheduler Status
```bash
php artisan schedule:list
```

### View Logs
```bash
# Application logs
tail -f storage/logs/laravel.log

# Queue worker output
# (if running in foreground)
```

### Database Checks
```sql
-- Verify recent game syncs
SELECT COUNT(*), MAX(updated_at) FROM cbb_games;

-- Check Elo calculation progress
SELECT COUNT(*), MAX(elo_rating) FROM cbb_elo_ratings WHERE season = 2026;

-- View latest predictions
SELECT COUNT(*), MAX(updated_at) FROM cbb_predictions;
```

## Troubleshooting

### Scheduler not running
1. Check crontab is set up correctly
2. Verify `php artisan schedule:list` shows tasks
3. Run `php artisan schedule:run` manually to test

### Jobs not processing
1. Ensure queue worker is running: `php artisan queue:work`
2. Check failed jobs: `php artisan queue:failed`
3. Retry failed jobs: `php artisan queue:retry all`

### Missing data
1. Run commands manually to identify the issue
2. Check API rate limits (ESPN may throttle)
3. Review logs for errors

## Season Management

The scheduler automatically uses the current year for the season. To change:

1. Edit `routes/console.php`
2. Update `--season=` parameter
3. No restart needed - scheduler picks up changes

## CBB vs NBA Differences

- **Week-based**: CBB uses week numbers instead of date ranges
- **Lower Home Court**: 70 Elo points vs NBA's 100 (more neutral site games)
- **Advanced Stats**: CBB tracks Four Factors instead of just efficiency
- **Pace Calculation**: Based on 40-minute games vs NBA's 48 minutes
- **Season Type**: 1=preseason, 2=regular, 3=NCAA Tournament

## Performance

- **Current week sync**: ~1 week = 1-2 minutes
- **Elo calculation**: ~300 games = ~5 seconds
- **Team metrics**: ~350 teams = ~3 seconds
- **Predictions**: 50 upcoming games = ~2 seconds

Total daily automation: **~5-10 minutes** (mostly during off-peak hours)
