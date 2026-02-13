# NBA Automated Pipeline

## Overview

The NBA data pipeline is fully automated using Laravel's task scheduler. It runs daily to sync games, calculate analytics, and generate predictions.

## Schedule

All tasks run automatically - **no manual intervention needed** during the season.

### Daily Tasks

| Time | Task | Description |
|------|------|-------------|
| **1:00 AM** | Sync Scoreboard | Fetches today's games + next 7 days (captures new scheduled games) |
| **3:30 AM** | Calculate Elo | Updates Elo ratings for all completed games |
| **4:00 AM** | Calculate Metrics | Calculates team offensive/defensive efficiency, tempo, SOS |
| **4:30 AM** | Generate Predictions | Creates predictions for all upcoming games |

### During Game Hours (6pm - 3am EST)

| Frequency | Task | Description |
|-----------|------|-------------|
| **Every 30 min** | Sync Game Details | Syncs stats, plays, and player data for completed games |

## How It Works

### 1. Sync Scoreboard (1:00 AM)
```bash
php artisan espn:sync-nba-games-scoreboard --from-date=today --to-date=+7days
```
- Queues API calls for today + next 7 days
- Captures new games as they're scheduled by the league
- Updates existing games if status changes

### 2. Sync Game Details (Every 30 min during games)
```bash
php artisan espn:sync-nba-game-details
```
- Finds completed games without stats (`STATUS_FINAL` + no `playerStats`)
- Queues detail sync jobs for each game
- Fetches: plays, player stats, team stats

### 3. Calculate Elo Ratings (3:30 AM)
```bash
php artisan nba:calculate-elo --season=2026
```
- Processes all completed games in chronological order
- Skips games that already have Elo history (idempotent)
- Updates team Elo ratings + saves history
- Uses: home court advantage, margin of victory, playoff multiplier

### 4. Calculate Team Metrics (4:00 AM)
```bash
php artisan nba:calculate-team-metrics --season=2026
```
- Calculates for all teams with completed games
- Updates existing metrics (idempotent)
- Metrics: Offensive/Defensive Efficiency, Net Rating, Tempo, SOS

### 5. Generate Predictions (4:30 AM)
```bash
php artisan nba:generate-predictions --season=2026
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
- **Game details**: Finds only games without stats

## Manual Overrides

You can still run commands manually anytime:

```bash
# Sync full season (one-time)
php artisan espn:sync-nba-games-scoreboard --season=2026

# Recalculate everything from scratch
php artisan nba:calculate-elo --season=2026 --reset
php artisan nba:calculate-team-metrics --season=2026
php artisan nba:generate-predictions --season=2026

# Sync specific game details
php artisan espn:sync-nba-game-details --game=12345
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
SELECT COUNT(*), MAX(updated_at) FROM nba_games;

-- Check Elo calculation progress
SELECT COUNT(*), MAX(date) FROM nba_elo_ratings WHERE season = 2026;

-- View latest predictions
SELECT COUNT(*), MAX(updated_at) FROM nba_predictions;
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

## Performance

- **Scoreboard sync**: ~273 API calls (full season) = 2-3 minutes
- **Game details**: ~1141 games × ~700ms = 13-15 minutes
- **Elo calculation**: ~1000 games = ~10 seconds
- **Team metrics**: 30 teams = ~1 second
- **Predictions**: 100 upcoming games = ~3 seconds

Total daily automation: **~20 minutes** (mostly during off-peak hours)
