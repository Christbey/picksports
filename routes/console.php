<?php

use App\Services\CommandHeartbeatService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

$inSeasonMonths = fn (array $months) => fn () => in_array(now()->month, $months, true);
$nbaInSeason = $inSeasonMonths([10, 11, 12, 1, 2, 3, 4, 5, 6]); // Oct-Jun
$cbbInSeason = $inSeasonMonths([11, 12, 1, 2, 3, 4]); // Nov-Apr
$wcbbInSeason = $inSeasonMonths([11, 12, 1, 2, 3, 4]); // Nov-Apr
$mlbInSeason = $inSeasonMonths([3, 4, 5, 6, 7, 8, 9, 10, 11]); // Mar-Nov
$wnbaInSeason = $inSeasonMonths([4, 5, 6, 7, 8, 9, 10]); // Apr-Oct
$nflInSeason = $inSeasonMonths([8, 9, 10, 11, 12, 1, 2]); // Aug-Feb
$nflDepthChartSeason = $inSeasonMonths([5, 6, 7, 8, 9, 10, 11, 12, 1, 2]); // May-Feb
$cfbInSeason = $inSeasonMonths([8, 9, 10, 11, 12, 1]); // Aug-Jan

// Season year helpers
$currentYear = (int) now()->year;
$fallSeasonYear = now()->month <= 2 ? $currentYear - 1 : $currentYear;
$cfbRegularSeasonStart = now()
    ->setYear($fallSeasonYear)
    ->setMonth(8)
    ->setDay(24);
$cfbCurrentRegularSeasonWeek = now()->lessThan($cfbRegularSeasonStart)
    ? 1
    : min(now()->diffInWeeks($cfbRegularSeasonStart) + 1, 15);

/*
|--------------------------------------------------------------------------
| External Heartbeat Ping URLs
|--------------------------------------------------------------------------
|
| If a Forge heartbeat URL exists for the scheduled name, ping it on
| success so Forge can alert when jobs stop running.
|
| Name-to-env mapping:
|   Scheduled name "NBA: Live Scoreboard Sync"
|   -> FORGE_HEARTBEAT_NBA_LIVE_SCOREBOARD_SYNC_URL
|
| Legacy fallback:
|   HEARTBEAT_LIVE_SCOREBOARD_URL still applies to "*Live Scoreboard*"
|   jobs when no Forge-specific URL is configured.
|
*/

$legacyLiveScoreboardHeartbeatUrl = config('services.heartbeat.live_scoreboard_url');
$resolveExternalHeartbeatUrl = function (string $sourceName) use ($legacyLiveScoreboardHeartbeatUrl): ?string {
    $slug = (string) Str::of($sourceName)
        ->lower()
        ->replaceMatches('/[^a-z0-9]+/', '_')
        ->trim('_');

    $forgeUrl = config("services.forge.heartbeats.{$slug}");
    if (is_string($forgeUrl) && $forgeUrl !== '') {
        return $forgeUrl;
    }

    if (
        str_contains($sourceName, 'Live Scoreboard')
        && is_string($legacyLiveScoreboardHeartbeatUrl)
        && $legacyLiveScoreboardHeartbeatUrl !== ''
    ) {
        return $legacyLiveScoreboardHeartbeatUrl;
    }

    return null;
};

$attachCommandHeartbeat = function ($event, string $command, string $sourceName) use ($resolveExternalHeartbeatUrl): void {
    $service = app(CommandHeartbeatService::class);
    $sport = $service->inferSportFromCommand($command);

    if ($externalHeartbeatUrl = $resolveExternalHeartbeatUrl($sourceName)) {
        $event->pingOnSuccess($externalHeartbeatUrl);
    }

    $event->onSuccess(function () use ($service, $command, $sport, $sourceName) {
        $service->recordSuccess($command, $sport, 'schedule', [
            'scheduled_name' => $sourceName,
        ]);
    });

    $event->onFailure(function () use ($service, $command, $sport, $sourceName) {
        $service->recordFailure($command, $sport, 'schedule', null, [
            'scheduled_name' => $sourceName,
        ]);
    });
};

$scheduleLiveScoreboardSync = function (
    string $command,
    string $betweenStart,
    string $betweenEnd,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $resolvedCommand = "{$command} ".date('Ymd');

    $event = Schedule::command($resolvedCommand)
        ->everyFiveMinutes()
        ->between($betweenStart, $betweenEnd)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $resolvedCommand, $name);
};

$scheduleDailySeasonJob = function (
    string $command,
    string $time,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->dailyAt($time)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$scheduleHalfHourlyWindowJob = function (
    string $command,
    string $betweenStart,
    string $betweenEnd,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->everyThirtyMinutes()
        ->between($betweenStart, $betweenEnd)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$scheduleOddsSyncWindow = function (
    string $command,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->everyFourHours()
        ->between('08:00', '23:00')
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$schedulePlayerPropsWindow = function (
    string $command,
    int $firstHour,
    int $secondHour,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->twiceDaily($firstHour, $secondHour)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$scheduleEveryMinuteJob = function (
    string $command,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->everyMinute()
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$schedulePredictionPipeline = function (
    string $sportCommandPrefix,
    string $sportLabel,
    int $season,
    callable $inSeason,
    array $times,
    array $preMetricJobs = []
) use ($scheduleDailySeasonJob) {
    foreach ($preMetricJobs as $job) {
        $scheduleDailySeasonJob(
            $job['command'],
            $job['time'],
            $inSeason,
            $job['name']
        );
    }

    $definitions = [
        'grade-predictions' => 'Grade Predictions',
        'calculate-elo' => 'Calculate Elo Ratings',
        'calculate-team-metrics' => 'Calculate Team Metrics',
        'generate-predictions' => 'Generate Predictions',
    ];

    foreach ($definitions as $commandSuffix => $jobLabel) {
        $scheduleDailySeasonJob(
            "{$sportCommandPrefix}:{$commandSuffix} --season={$season}",
            $times[$commandSuffix],
            $inSeason,
            "{$sportLabel}: {$jobLabel}"
        );
    }
};

$scheduleWeeklySeasonJob = function (
    string $command,
    int $dayOfWeek,
    string $time,
    callable $inSeason,
    string $name
) use ($attachCommandHeartbeat) {
    $event = Schedule::command($command)
        ->weeklyOn($dayOfWeek, $time)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    $attachCommandHeartbeat($event, $command, $name);
};

$scheduleEpaLifecycle = function (
    string $sport,
    string $label,
    callable $seasonResolver,
    callable $inSeason
) use ($scheduleDailySeasonJob, $scheduleWeeklySeasonJob) {
    $season = (int) $seasonResolver();
    $fromSeason = $season - 1;

    $scheduleDailySeasonJob(
        "sports:build-epa-state-baseline {$sport} --season={$season} --from-season={$fromSeason}",
        '02:20',
        $inSeason,
        "{$label}: Build EPA State Baseline"
    );

    $scheduleWeeklySeasonJob(
        "sports:report-epa-blend-calibration {$sport} --season={$season}",
        1,
        '02:50',
        $inSeason,
        "{$label}: Report EPA Blend Calibration"
    );
};

$scheduleSportPipeline = function (
    string $preSyncCommand,
    string $preSyncTime,
    string $preSyncName,
    string $liveCommand,
    string $liveBetweenStart,
    string $liveBetweenEnd,
    string $liveName,
    ?string $detailsCommand,
    ?string $detailsBetweenStart,
    ?string $detailsBetweenEnd,
    ?string $detailsName,
    string $sportCommandPrefix,
    string $sportLabel,
    int $season,
    callable $inSeason,
    array $predictionTimes,
    string $oddsCommand,
    string $oddsName,
    ?string $playerPropsCommand = null,
    ?int $playerPropsFirstHour = null,
    ?int $playerPropsSecondHour = null,
    ?string $playerPropsName = null,
    array $preMetricJobs = []
) use (
    $scheduleDailySeasonJob,
    $scheduleLiveScoreboardSync,
    $scheduleHalfHourlyWindowJob,
    $schedulePredictionPipeline,
    $scheduleOddsSyncWindow,
    $schedulePlayerPropsWindow
) {
    $scheduleDailySeasonJob($preSyncCommand, $preSyncTime, $inSeason, $preSyncName);
    $scheduleLiveScoreboardSync($liveCommand, $liveBetweenStart, $liveBetweenEnd, $inSeason, $liveName);

    if ($detailsCommand && $detailsBetweenStart && $detailsBetweenEnd && $detailsName) {
        $scheduleHalfHourlyWindowJob(
            $detailsCommand,
            $detailsBetweenStart,
            $detailsBetweenEnd,
            $inSeason,
            $detailsName
        );
    }

    $schedulePredictionPipeline(
        $sportCommandPrefix,
        $sportLabel,
        $season,
        $inSeason,
        $predictionTimes,
        $preMetricJobs
    );

    $scheduleOddsSyncWindow($oddsCommand, $inSeason, $oddsName);

    if ($playerPropsCommand && $playerPropsFirstHour !== null && $playerPropsSecondHour !== null && $playerPropsName) {
        $schedulePlayerPropsWindow(
            $playerPropsCommand,
            $playerPropsFirstHour,
            $playerPropsSecondHour,
            $inSeason,
            $playerPropsName
        );
    }
};

/*
|--------------------------------------------------------------------------
| Sport Pipelines
|--------------------------------------------------------------------------
*/

// NBA
$scheduleSportPipeline(
    'espn:sync-nba-games-scoreboard --from-date='.date('Y-m-d').' --to-date='.date('Y-m-d', strtotime('+7 days')),
    '01:00',
    'NBA: Sync Scoreboard (Today + 7 Days)',
    'espn:sync-nba-games-scoreboard',
    '18:00',
    '03:00',
    'NBA: Live Scoreboard Sync',
    'espn:sync-nba-game-details',
    '18:00',
    '03:00',
    'NBA: Sync Game Details',
    'nba',
    'NBA',
    $fallSeasonYear,
    $nbaInSeason,
    [
        'grade-predictions' => '03:30',
        'calculate-elo' => '04:00',
        'calculate-team-metrics' => '04:30',
        'generate-predictions' => '05:00',
    ],
    'nba:sync-odds',
    'NBA: Sync Odds',
    'nba:sync-player-props',
    10,
    14,
    'NBA: Sync Player Props'
);
$scheduleDailySeasonJob(
    "nba:generate-playoff-forecast --season={$fallSeasonYear}",
    '05:15',
    $nbaInSeason,
    'NBA: Generate Playoff Forecast'
);
$scheduleDailySeasonJob(
    'espn:sync-nba-players',
    '00:45',
    $nbaInSeason,
    'NBA: Sync Players'
);
$scheduleDailySeasonJob(
    'espn:sync-nba-game-details --refresh-existing --lookback-days=3',
    '03:15',
    $nbaInSeason,
    'NBA: Refresh Recent Game Details'
);
$scheduleDailySeasonJob(
    'healthcheck:validate-data --sport=nba',
    '07:00',
    $nbaInSeason,
    'NBA: Validate Data Completeness'
);
$scheduleOddsSyncWindow(
    "sports:sync-futures-odds --sport=nba --season={$fallSeasonYear}",
    $nbaInSeason,
    'NBA: Sync Futures Odds'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-nba-injuries',
    '08:00',
    '23:00',
    $nbaInSeason,
    'NBA: Sync Injuries'
);
$scheduleEpaLifecycle('nba', 'NBA', fn () => $fallSeasonYear, $nbaInSeason);

// CBB
$scheduleWeeklySeasonJob(
    'espn:sync-cbb-teams',
    0,
    '01:15',
    $cbbInSeason,
    'CBB: Sync Teams'
);
$cbbTeamSchedulesEvent = Schedule::command('espn:sync-cbb-all-team-schedules')
    ->weeklyOn(0, '01:30')
    ->when($cbbInSeason)
    ->name('CBB: Sync All Team Schedules')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($cbbTeamSchedulesEvent, 'espn:sync-cbb-all-team-schedules', 'CBB: Sync All Team Schedules');

$scheduleDailySeasonJob(
    "cbb:sync-tournament-structure --season={$fallSeasonYear}",
    '01:00',
    $cbbInSeason,
    'CBB: Sync Tournament Structure'
);
$scheduleWeeklySeasonJob(
    'espn:sync-cbb-players',
    0,
    '02:15',
    $cbbInSeason,
    'CBB: Sync Players'
);
$scheduleDailySeasonJob(
    'espn:backfill-cbb-stale-games --dispatch=0 --limit=100',
    '03:00',
    $cbbInSeason,
    'CBB: Backfill Stale Games'
);

$scheduleSportPipeline(
    'espn:sync-cbb-current',
    '02:00',
    'CBB: Sync Current Week',
    'espn:sync-cbb-games-scoreboard',
    '12:00',
    '01:00',
    'CBB: Live Scoreboard Sync',
    'espn:sync-cbb-game-details',
    '14:00',
    '02:00',
    'CBB: Sync Game Details',
    'cbb',
    'CBB',
    $fallSeasonYear,
    $cbbInSeason,
    [
        'grade-predictions' => '05:00',
        'calculate-elo' => '05:30',
        'calculate-team-metrics' => '06:00',
        'generate-predictions' => '06:30',
    ],
    'cbb:sync-odds',
    'CBB: Sync Odds',
    'cbb:sync-player-props',
    12,
    17,
    'CBB: Sync Player Props'
);
$scheduleDailySeasonJob(
    "cbb:generate-tournament-forecast --season={$fallSeasonYear}",
    '07:00',
    $cbbInSeason,
    'CBB: Generate Tournament Forecast'
);
$scheduleDailySeasonJob(
    "cbb:recalculate-tournament-outlook {$fallSeasonYear} --source=scheduled",
    '07:15',
    $cbbInSeason,
    'CBB: Recalculate Tournament Outlook'
);
$scheduleOddsSyncWindow(
    "sports:sync-futures-odds --sport=cbb --season={$fallSeasonYear}",
    $cbbInSeason,
    'CBB: Sync Futures Odds'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-cbb-injuries',
    '08:00',
    '23:00',
    $cbbInSeason,
    'CBB: Sync Injuries'
);
$scheduleEpaLifecycle('cbb', 'CBB', fn () => $fallSeasonYear, $cbbInSeason);

// WCBB
$wcbbTeamSchedulesEvent = Schedule::command("espn:sync-wcbb-schedules --season={$fallSeasonYear}")
    ->weeklyOn(0, '01:45')
    ->when($wcbbInSeason)
    ->name('WCBB: Sync All Team Schedules')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($wcbbTeamSchedulesEvent, "espn:sync-wcbb-schedules --season={$fallSeasonYear}", 'WCBB: Sync All Team Schedules');

$scheduleDailySeasonJob('espn:sync-wcbb-teams', '02:45', $wcbbInSeason, 'WCBB: Sync Teams (Daily)');
$scheduleDailySeasonJob('espn:sync-wcbb-game-details', '03:15', $wcbbInSeason, 'WCBB: Sync Game Details (Daily)');

$scheduleSportPipeline(
    'espn:sync-wcbb-current',
    '03:00',
    'WCBB: Sync Current Week',
    'espn:sync-wcbb-games-scoreboard',
    '12:00',
    '01:00',
    'WCBB: Live Scoreboard Sync',
    'espn:sync-wcbb-game-details',
    '14:00',
    '02:00',
    'WCBB: Sync Game Details',
    'wcbb',
    'WCBB',
    $fallSeasonYear,
    $wcbbInSeason,
    [
        'grade-predictions' => '03:30',
        'calculate-elo' => '04:00',
        'calculate-team-metrics' => '04:30',
        'generate-predictions' => '05:00',
    ],
    'wcbb:sync-odds',
    'WCBB: Sync Odds'
);
$scheduleDailySeasonJob(
    "wcbb:generate-tournament-forecast --season={$fallSeasonYear}",
    '05:15',
    $wcbbInSeason,
    'WCBB: Generate Tournament Forecast'
);
$scheduleOddsSyncWindow(
    "sports:sync-futures-odds --sport=wcbb --season={$fallSeasonYear}",
    $wcbbInSeason,
    'WCBB: Sync Futures Odds'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-wcbb-injuries',
    '08:00',
    '23:00',
    $wcbbInSeason,
    'WCBB: Sync Injuries'
);
$scheduleEpaLifecycle('wcbb', 'WCBB', fn () => $fallSeasonYear, $wcbbInSeason);

// MLB
$scheduleSportPipeline(
    'espn:sync-mlb-schedules --season='.$currentYear,
    '01:30',
    'MLB: Sync Schedules',
    'espn:sync-mlb-games-scoreboard',
    '13:00',
    '04:00',
    'MLB: Live Scoreboard Sync',
    'espn:sync-mlb-game-details',
    '16:00',
    '04:00',
    'MLB: Sync Game Details',
    'mlb',
    'MLB',
    $currentYear,
    $mlbInSeason,
    [
        'grade-predictions' => '04:30',
        'calculate-elo' => '05:00',
        'calculate-team-metrics' => '05:30',
        'generate-predictions' => '06:00',
    ],
    'mlb:sync-odds',
    'MLB: Sync Odds',
    'mlb:sync-player-props',
    11,
    16,
    'MLB: Sync Player Props',
    [
        [
            'command' => 'mlb:calculate-bullpen-ratings --season='.$currentYear.' --season-type='.config('mlb.season.types.regular').' --date='.date('Y-m-d'),
            'time' => '05:45',
            'name' => 'MLB: Calculate Bullpen Ratings',
        ],
        [
            'command' => 'mlb:sync-game-weather --season='.$currentYear.' --days-back=0 --days-forward=7 --force',
            'time' => '05:50',
            'name' => 'MLB: Sync Game Weather',
        ],
    ]
);
$scheduleDailySeasonJob(
    "mlb:generate-playoff-forecast --season={$currentYear}",
    '06:15',
    $mlbInSeason,
    'MLB: Generate Playoff Forecast'
);
$scheduleDailySeasonJob(
    "mlb:snapshot-bet-filter --season={$currentYear}",
    '06:20',
    $mlbInSeason,
    'MLB: Snapshot Bet Filter'
);
$scheduleDailySeasonJob(
    "sports:ai-daily-predictions --sport=mlb --season={$currentYear}",
    '06:30',
    $mlbInSeason,
    'MLB: AI Daily Prediction Analysis'
);
$scheduleOddsSyncWindow(
    "sports:sync-futures-odds --sport=mlb --season={$currentYear}",
    $mlbInSeason,
    'MLB: Sync Futures Odds'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-mlb-injuries',
    '08:00',
    '23:00',
    $mlbInSeason,
    'MLB: Sync Injuries'
);
$scheduleHalfHourlyWindowJob(
    'mlb:refresh-probable-pitchers --days-ahead=2',
    '06:00',
    '23:00',
    $mlbInSeason,
    'MLB: Refresh Probable Pitchers'
);

// WNBA
$scheduleSportPipeline(
    'espn:sync-wnba-games-scoreboard --from-date='.date('Y-m-d').' --to-date='.date('Y-m-d', strtotime('+7 days')),
    '01:00',
    'WNBA: Sync Scoreboard (Today + 7 Days)',
    'espn:sync-wnba-games-scoreboard',
    '19:00',
    '23:00',
    'WNBA: Live Scoreboard Sync',
    'espn:sync-wnba-game-details',
    '19:00',
    '23:00',
    'WNBA: Sync Game Details',
    'wnba',
    'WNBA',
    $currentYear,
    $wnbaInSeason,
    [
        'grade-predictions' => '00:00',
        'calculate-elo' => '00:30',
        'calculate-team-metrics' => '01:30',
        'generate-predictions' => '02:00',
    ],
    'wnba:sync-odds',
    'WNBA: Sync Odds',
    'wnba:sync-player-props',
    10,
    15,
    'WNBA: Sync Player Props'
);
$scheduleDailySeasonJob(
    "sports:ai-daily-predictions --sport=wnba --season={$currentYear}",
    '02:15',
    $wnbaInSeason,
    'WNBA: AI Daily Prediction Analysis'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-wnba-injuries',
    '08:00',
    '23:00',
    $wnbaInSeason,
    'WNBA: Sync Injuries'
);

// NFL
$scheduleSportPipeline(
    'espn:sync-nfl-current',
    '08:00',
    'NFL: Sync Current Week',
    'espn:sync-nfl-games-scoreboard',
    '17:00',
    '02:00',
    'NFL: Live Scoreboard Sync',
    'espn:sync-nfl-game-details',
    '17:00',
    '02:00',
    'NFL: Sync Game Details',
    'nfl',
    'NFL',
    $fallSeasonYear,
    $nflInSeason,
    [
        'grade-predictions' => '08:30',
        'calculate-elo' => '09:00',
        'calculate-team-metrics' => '09:30',
        'generate-predictions' => '10:00',
    ],
    'nfl:sync-odds',
    'NFL: Sync Odds',
    'nfl:sync-player-props',
    10,
    15,
    'NFL: Sync Player Props'
);
$scheduleWeeklySeasonJob(
    "espn:sync-nfl-depth-charts --season={$fallSeasonYear}",
    1,
    '07:45',
    $nflDepthChartSeason,
    'NFL: Sync Depth Charts'
);
$scheduleDailySeasonJob(
    "nfl:sync-game-weather --season={$fallSeasonYear} --days-back=0 --days-forward=7 --force",
    '09:45',
    $nflInSeason,
    'NFL: Sync Game Weather'
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-nfl-injuries',
    '08:00',
    '23:00',
    $nflInSeason,
    'NFL: Sync Injuries'
);
$scheduleOddsSyncWindow(
    "sports:sync-futures-odds --sport=nfl --season={$fallSeasonYear}",
    $nflInSeason,
    'NFL: Sync Futures Odds'
);
$scheduleEpaLifecycle('nfl', 'NFL', fn () => $fallSeasonYear, $nflInSeason);

// CFB
$scheduleSportPipeline(
    'espn:sync-cfb-current',
    '07:00',
    'CFB: Sync Current Week',
    'espn:sync-cfb-games-scoreboard',
    '12:00',
    '02:00',
    'CFB: Live Scoreboard Sync',
    'espn:sync-cfb-game-details',
    '14:00',
    '02:00',
    'CFB: Sync Game Details',
    'cfb',
    'CFB',
    $fallSeasonYear,
    $cfbInSeason,
    [
        'grade-predictions' => '03:00',
        'calculate-elo' => '03:30',
        'calculate-team-metrics' => '04:00',
        'generate-predictions' => '04:30',
    ],
    'cfb:sync-odds',
    'CFB: Sync Odds',
    null,
    null,
    null,
    null,
    [
        [
            'command' => "cfb:import-fpi --season={$fallSeasonYear} --week={$cfbCurrentRegularSeasonWeek}",
            'time' => '03:45',
            'name' => 'CFB: Import FPI',
        ],
    ]
);
$scheduleHalfHourlyWindowJob(
    'espn:sync-cfb-injuries',
    '08:00',
    '23:00',
    $cfbInSeason,
    'CFB: Sync Injuries'
);

/*
|--------------------------------------------------------------------------
| Maintenance
|--------------------------------------------------------------------------
*/

$pruneFailedJobsEvent = Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:20')
    ->name('Queue: Prune Failed Jobs')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($pruneFailedJobsEvent, 'queue:prune-failed --hours=168', 'Queue: Prune Failed Jobs');

$scheduleEveryMinuteJob(
    'alerts:send-daily-digests',
    'Alerts: Send Daily Digests'
);

$adminEmailReportEvent = Schedule::command('alerts:send-admin-email-report')
    ->dailyAt((string) config('alerts.admin_report.daily_time', '07:30'))
    ->when(fn () => (bool) config('alerts.admin_report.enabled', true))
    ->name('Alerts: Send Admin Email Report')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($adminEmailReportEvent, 'alerts:send-admin-email-report', 'Alerts: Send Admin Email Report');
