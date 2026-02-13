<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| NBA Automated Pipeline
|--------------------------------------------------------------------------
|
| Automated schedule for syncing NBA games, calculating analytics, and
| generating predictions. Runs daily during the NBA season.
|
*/

// 1. Sync today's games + next 7 days (captures new scheduled games)
Schedule::command('espn:sync-nba-games-scoreboard --from-date='.date('Y-m-d').' --to-date='.date('Y-m-d', strtotime('+7 days')))
    ->dailyAt('01:00')
    ->name('NBA: Sync Scoreboard (Today + 7 Days)')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync game details for completed games (during game hours)
Schedule::command('espn:sync-nba-game-details')
    ->everyThirtyMinutes()
    ->between('18:00', '03:00') // NBA games typically 7pm-2am EST
    ->name('NBA: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Calculate Elo ratings (after games complete)
Schedule::command('nba:calculate-elo --season='.date('Y'))
    ->dailyAt('03:30')
    ->name('NBA: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate team metrics (after Elo updates)
Schedule::command('nba:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('04:00')
    ->name('NBA: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Generate predictions for upcoming games (after metrics update)
Schedule::command('nba:generate-predictions --season='.date('Y'))
    ->dailyAt('04:30')
    ->name('NBA: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| CBB Automated Pipeline
|--------------------------------------------------------------------------
|
| Automated schedule for syncing CBB games, calculating analytics, and
| generating predictions. Runs daily during the college basketball season.
|
*/

// 1. Sync all team schedules (comprehensive schedule sync)
Schedule::command('espn:sync-cbb-all-team-schedules')
    ->weeklyOn(0, '01:30') // Sunday at 1:30 AM
    ->name('CBB: Sync All Team Schedules')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-cbb-current')
    ->dailyAt('02:00')
    ->name('CBB: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Grade predictions for completed games (after sync)
Schedule::command('cbb:grade-predictions --season='.date('Y'))
    ->dailyAt('02:30')
    ->name('CBB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate Elo ratings (after games complete)
Schedule::command('cbb:calculate-elo --season='.date('Y'))
    ->dailyAt('05:00')
    ->name('CBB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate team metrics (after Elo updates)
Schedule::command('cbb:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('05:30')
    ->name('CBB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Generate predictions for upcoming games (after metrics update)
Schedule::command('cbb:generate-predictions --season='.date('Y'))
    ->dailyAt('06:00')
    ->name('CBB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| WCBB Automated Pipeline
|--------------------------------------------------------------------------
|
| Automated schedule for syncing WCBB games, calculating analytics, and
| generating predictions. Runs daily during the women's college basketball season.
|
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-wcbb-current')
    ->dailyAt('03:00')
    ->name('WCBB: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync game details (box scores) for completed games without team stats
Schedule::command('espn:sync-wcbb-game-details --season='.date('Y'))
    ->dailyAt('03:15')
    ->name('WCBB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Grade predictions for completed games (after sync)
Schedule::command('wcbb:grade-predictions --season='.date('Y'))
    ->dailyAt('03:30')
    ->name('WCBB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate Elo ratings (after games complete)
Schedule::command('wcbb:calculate-elo --season='.date('Y'))
    ->dailyAt('06:30')
    ->name('WCBB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate team metrics (after Elo updates)
Schedule::command('wcbb:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('07:00')
    ->name('WCBB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Generate predictions for upcoming games (after metrics update)
Schedule::command('wcbb:generate-predictions --season='.date('Y'))
    ->dailyAt('07:30')
    ->name('WCBB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| MLB Automated Pipeline
|--------------------------------------------------------------------------
|
| Automated schedule for syncing MLB games, calculating Elo ratings, and
| generating predictions. Runs daily during the MLB season.
|
*/

// 1. Sync schedules for all teams (captures new scheduled games)
Schedule::command('espn:sync-mlb-schedules --season=2026')
    ->dailyAt('01:30')
    ->name('MLB: Sync Schedules')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync game details for completed games (during typical game hours)
// MLB games: Day games 1pm-4pm, night games 7pm-11pm EST
Schedule::command('espn:sync-mlb-game-details')
    ->everyThirtyMinutes()
    ->between('16:00', '04:00') // 4pm-4am covers all game times + west coast
    ->name('MLB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Calculate Elo ratings (after games complete)
Schedule::command('mlb:calculate-elo --season=2026')
    ->dailyAt('04:30')
    ->name('MLB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate team metrics (after Elo updates)
Schedule::command('mlb:calculate-team-metrics --season=2026')
    ->dailyAt('05:00')
    ->name('MLB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Generate predictions for upcoming games (after metrics update)
Schedule::command('mlb:generate-predictions --season=2026')
    ->dailyAt('05:30')
    ->name('MLB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| NFL Automated Pipeline
|--------------------------------------------------------------------------
|
| Automated schedule for syncing NFL games, calculating ELO ratings, and
| generating predictions. Runs daily during the NFL season.
|
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-nfl-current')
    ->dailyAt('08:00')
    ->name('NFL: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync game details for completed games (during typical NFL game hours)
// NFL games: Thursday 8:15pm, Sunday 1pm/4pm/8pm, Monday 8:15pm EST
Schedule::command('espn:sync-nfl-game-details')
    ->everyThirtyMinutes()
    ->between('17:00', '02:00') // 5pm-2am covers all game times
    ->name('NFL: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Calculate Elo ratings (after games complete)
Schedule::command('nfl:calculate-elo --season=2025')
    ->dailyAt('08:30')
    ->name('NFL: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Generate predictions for upcoming games (after Elo updates)
Schedule::command('nfl:generate-predictions --season=2025')
    ->dailyAt('09:00')
    ->name('NFL: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();
