<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Season Guards
|--------------------------------------------------------------------------
|
| Each sport only runs during its season (with a 1-month buffer on each
| end for preseason/postseason sync). This prevents hundreds of wasted
| job executions per day for off-season sports.
|
*/

$nbaInSeason = fn () => in_array(now()->month, [10, 11, 12, 1, 2, 3, 4, 5, 6]); // Oct-Jun
$cbbInSeason = fn () => in_array(now()->month, [11, 12, 1, 2, 3, 4]); // Nov-Apr
$wcbbInSeason = fn () => in_array(now()->month, [11, 12, 1, 2, 3, 4]); // Nov-Apr
$mlbInSeason = fn () => in_array(now()->month, [3, 4, 5, 6, 7, 8, 9, 10, 11]); // Mar-Nov
$wnbaInSeason = fn () => in_array(now()->month, [4, 5, 6, 7, 8, 9, 10]); // Apr-Oct
$nflInSeason = fn () => in_array(now()->month, [8, 9, 10, 11, 12, 1, 2]); // Aug-Feb
$cfbInSeason = fn () => in_array(now()->month, [8, 9, 10, 11, 12, 1]); // Aug-Jan

/*
|--------------------------------------------------------------------------
| Heartbeat Ping URL
|--------------------------------------------------------------------------
|
| External monitoring URL (e.g. BetterStack, OhDear) pinged on each
| successful live scoreboard sync. Set HEARTBEAT_LIVE_SCOREBOARD_URL
| in .env to enable.
|
*/

$heartbeatUrl = config('services.heartbeat.live_scoreboard_url');

/*
|--------------------------------------------------------------------------
| NBA Automated Pipeline (Oct - Jun)
|--------------------------------------------------------------------------
*/

// 1. Sync today's games + next 7 days (captures new scheduled games)
Schedule::command('espn:sync-nba-games-scoreboard --from-date='.date('Y-m-d').' --to-date='.date('Y-m-d', strtotime('+7 days')))
    ->dailyAt('01:00')
    ->when($nbaInSeason)
    ->name('NBA: Sync Scoreboard (Today + 7 Days)')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$nbaLiveSync = Schedule::command('espn:sync-nba-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('18:00', '03:00')
    ->when($nbaInSeason)
    ->name('NBA: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $nbaLiveSync->pingOnSuccess($heartbeatUrl);
}

// 3. Sync game details for completed games (during game hours)
Schedule::command('espn:sync-nba-game-details')
    ->everyThirtyMinutes()
    ->between('18:00', '03:00')
    ->when($nbaInSeason)
    ->name('NBA: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Grade predictions for completed games (after all games finalize)
Schedule::command('nba:grade-predictions --season='.date('Y'))
    ->dailyAt('03:30')
    ->when($nbaInSeason)
    ->name('NBA: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate Elo ratings (after grading)
Schedule::command('nba:calculate-elo --season='.date('Y'))
    ->dailyAt('04:00')
    ->when($nbaInSeason)
    ->name('NBA: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Calculate team metrics (after Elo updates)
Schedule::command('nba:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('04:30')
    ->when($nbaInSeason)
    ->name('NBA: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 7. Generate predictions for upcoming games (after metrics update)
Schedule::command('nba:generate-predictions --season='.date('Y'))
    ->dailyAt('05:00')
    ->when($nbaInSeason)
    ->name('NBA: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| CBB Automated Pipeline (Nov - Apr)
|--------------------------------------------------------------------------
*/

// 1. Sync all team schedules (comprehensive schedule sync)
Schedule::command('espn:sync-cbb-all-team-schedules')
    ->weeklyOn(0, '01:30')
    ->when($cbbInSeason)
    ->name('CBB: Sync All Team Schedules')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-cbb-current')
    ->dailyAt('02:00')
    ->when($cbbInSeason)
    ->name('CBB: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2a. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$cbbLiveSync = Schedule::command('espn:sync-cbb-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('12:00', '01:00')
    ->when($cbbInSeason)
    ->name('CBB: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $cbbLiveSync->pingOnSuccess($heartbeatUrl);
}

// 2b. Sync game details (box scores + stats) for completed games during game hours
Schedule::command('espn:sync-cbb-game-details')
    ->everyThirtyMinutes()
    ->between('14:00', '02:00')
    ->when($cbbInSeason)
    ->name('CBB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Grade predictions for completed games (after all games finalize)
Schedule::command('cbb:grade-predictions --season='.date('Y'))
    ->dailyAt('05:00')
    ->when($cbbInSeason)
    ->name('CBB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate Elo ratings (after grading)
Schedule::command('cbb:calculate-elo --season='.date('Y'))
    ->dailyAt('05:30')
    ->when($cbbInSeason)
    ->name('CBB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate team metrics (after Elo updates, requires box score stats)
Schedule::command('cbb:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('06:00')
    ->when($cbbInSeason)
    ->name('CBB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Generate predictions for upcoming games (after metrics update)
Schedule::command('cbb:generate-predictions --season='.date('Y'))
    ->dailyAt('06:30')
    ->when($cbbInSeason)
    ->name('CBB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| WCBB Automated Pipeline (Nov - Apr)
|--------------------------------------------------------------------------
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-wcbb-current')
    ->dailyAt('03:00')
    ->when($wcbbInSeason)
    ->name('WCBB: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Sync game details (box scores) for completed games without team stats
Schedule::command('espn:sync-wcbb-game-details')
    ->dailyAt('03:15')
    ->when($wcbbInSeason)
    ->name('WCBB: Sync Game Details (Daily)')
    ->withoutOverlapping()
    ->runInBackground();

// 2a. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$wcbbLiveSync = Schedule::command('espn:sync-wcbb-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('12:00', '01:00')
    ->when($wcbbInSeason)
    ->name('WCBB: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $wcbbLiveSync->pingOnSuccess($heartbeatUrl);
}

// 2b. Sync game details during game hours (box scores for completed games)
Schedule::command('espn:sync-wcbb-game-details')
    ->everyThirtyMinutes()
    ->between('14:00', '02:00')
    ->when($wcbbInSeason)
    ->name('WCBB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 3. Grade predictions for completed games (after all games finalize)
Schedule::command('wcbb:grade-predictions --season='.date('Y'))
    ->dailyAt('03:30')
    ->when($wcbbInSeason)
    ->name('WCBB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate Elo ratings (after grading)
Schedule::command('wcbb:calculate-elo --season='.date('Y'))
    ->dailyAt('04:00')
    ->when($wcbbInSeason)
    ->name('WCBB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate team metrics (after Elo updates)
Schedule::command('wcbb:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('04:30')
    ->when($wcbbInSeason)
    ->name('WCBB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Generate predictions for upcoming games (after metrics update)
Schedule::command('wcbb:generate-predictions --season='.date('Y'))
    ->dailyAt('05:00')
    ->when($wcbbInSeason)
    ->name('WCBB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| MLB Automated Pipeline (Mar - Nov)
|--------------------------------------------------------------------------
*/

// 1. Sync schedules for all teams (captures new scheduled games)
Schedule::command('espn:sync-mlb-schedules --season=2026')
    ->dailyAt('01:30')
    ->when($mlbInSeason)
    ->name('MLB: Sync Schedules')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$mlbLiveSync = Schedule::command('espn:sync-mlb-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('13:00', '04:00')
    ->when($mlbInSeason)
    ->name('MLB: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $mlbLiveSync->pingOnSuccess($heartbeatUrl);
}

// 3. Sync game details for completed games (during typical game hours)
Schedule::command('espn:sync-mlb-game-details')
    ->everyThirtyMinutes()
    ->between('16:00', '04:00')
    ->when($mlbInSeason)
    ->name('MLB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Grade predictions for completed games (after all games finalize)
Schedule::command('mlb:grade-predictions --season=2026')
    ->dailyAt('04:30')
    ->when($mlbInSeason)
    ->name('MLB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate Elo ratings (after grading)
Schedule::command('mlb:calculate-elo --season=2026')
    ->dailyAt('05:00')
    ->when($mlbInSeason)
    ->name('MLB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Calculate team metrics (after Elo updates)
Schedule::command('mlb:calculate-team-metrics --season=2026')
    ->dailyAt('05:30')
    ->when($mlbInSeason)
    ->name('MLB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 7. Generate predictions for upcoming games (after metrics update)
Schedule::command('mlb:generate-predictions --season=2026')
    ->dailyAt('06:00')
    ->when($mlbInSeason)
    ->name('MLB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| WNBA Automated Pipeline (Apr - Oct)
|--------------------------------------------------------------------------
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-wnba-current')
    ->dailyAt('01:00')
    ->when($wnbaInSeason)
    ->name('WNBA: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$wnbaLiveSync = Schedule::command('espn:sync-wnba-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('19:00', '23:00')
    ->when($wnbaInSeason)
    ->name('WNBA: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $wnbaLiveSync->pingOnSuccess($heartbeatUrl);
}

// 3. Grade predictions for completed games (after all games finalize)
Schedule::command('wnba:grade-predictions --season='.date('Y'))
    ->dailyAt('00:00')
    ->when($wnbaInSeason)
    ->name('WNBA: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Calculate Elo ratings (after grading)
Schedule::command('wnba:calculate-elo --season='.date('Y'))
    ->dailyAt('00:30')
    ->when($wnbaInSeason)
    ->name('WNBA: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate team metrics (after Elo updates)
Schedule::command('wnba:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('01:30')
    ->when($wnbaInSeason)
    ->name('WNBA: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Generate predictions for upcoming games (after metrics update)
Schedule::command('wnba:generate-predictions --season='.date('Y'))
    ->dailyAt('02:00')
    ->when($wnbaInSeason)
    ->name('WNBA: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| NFL Automated Pipeline (Aug - Feb)
|--------------------------------------------------------------------------
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-nfl-current')
    ->dailyAt('08:00')
    ->when($nflInSeason)
    ->name('NFL: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$nflLiveSync = Schedule::command('espn:sync-nfl-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('17:00', '02:00')
    ->when($nflInSeason)
    ->name('NFL: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $nflLiveSync->pingOnSuccess($heartbeatUrl);
}

// 3. Sync game details for completed games (during typical NFL game hours)
Schedule::command('espn:sync-nfl-game-details')
    ->everyThirtyMinutes()
    ->between('17:00', '02:00')
    ->when($nflInSeason)
    ->name('NFL: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Grade predictions for completed games (after all games finalize)
Schedule::command('nfl:grade-predictions --season=2025')
    ->dailyAt('08:30')
    ->when($nflInSeason)
    ->name('NFL: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate Elo ratings (after grading)
Schedule::command('nfl:calculate-elo --season=2025')
    ->dailyAt('09:00')
    ->when($nflInSeason)
    ->name('NFL: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Calculate team metrics (after Elo updates)
Schedule::command('nfl:calculate-team-metrics --season=2025')
    ->dailyAt('09:30')
    ->when($nflInSeason)
    ->name('NFL: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 7. Generate predictions for upcoming games (after metrics update)
Schedule::command('nfl:generate-predictions --season=2025')
    ->dailyAt('10:00')
    ->when($nflInSeason)
    ->name('NFL: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| CFB Automated Pipeline (Aug - Jan)
|--------------------------------------------------------------------------
*/

// 1. Sync current week's games (captures new scheduled games)
Schedule::command('espn:sync-cfb-current')
    ->dailyAt('07:00')
    ->when($cfbInSeason)
    ->name('CFB: Sync Current Week')
    ->withoutOverlapping()
    ->runInBackground();

// 2. Live scoreboard sync during game hours (updates scores + live predictions every 5 min)
$cfbLiveSync = Schedule::command('espn:sync-cfb-games-scoreboard '.date('Ymd'))
    ->everyFiveMinutes()
    ->between('12:00', '02:00')
    ->when($cfbInSeason)
    ->name('CFB: Live Scoreboard Sync')
    ->withoutOverlapping()
    ->runInBackground();

if ($heartbeatUrl) {
    $cfbLiveSync->pingOnSuccess($heartbeatUrl);
}

// 3. Sync game details during game hours (box scores for completed games)
Schedule::command('espn:sync-cfb-game-details')
    ->everyThirtyMinutes()
    ->between('14:00', '02:00')
    ->when($cfbInSeason)
    ->name('CFB: Sync Game Details')
    ->withoutOverlapping()
    ->runInBackground();

// 4. Grade predictions for completed games (after all games finalize)
Schedule::command('cfb:grade-predictions --season='.date('Y'))
    ->dailyAt('03:00')
    ->when($cfbInSeason)
    ->name('CFB: Grade Predictions')
    ->withoutOverlapping()
    ->runInBackground();

// 5. Calculate Elo ratings (after grading)
Schedule::command('cfb:calculate-elo --season='.date('Y'))
    ->dailyAt('03:30')
    ->when($cfbInSeason)
    ->name('CFB: Calculate Elo Ratings')
    ->withoutOverlapping()
    ->runInBackground();

// 6. Calculate team metrics (after Elo updates)
Schedule::command('cfb:calculate-team-metrics --season='.date('Y'))
    ->dailyAt('04:00')
    ->when($cfbInSeason)
    ->name('CFB: Calculate Team Metrics')
    ->withoutOverlapping()
    ->runInBackground();

// 7. Generate predictions for upcoming games (after metrics update)
Schedule::command('cfb:generate-predictions --season='.date('Y'))
    ->dailyAt('04:30')
    ->when($cfbInSeason)
    ->name('CFB: Generate Predictions')
    ->withoutOverlapping()
    ->runInBackground();
