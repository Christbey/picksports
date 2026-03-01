<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\CommandHeartbeatService;

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
$cfbInSeason = $inSeasonMonths([8, 9, 10, 11, 12, 1]); // Aug-Jan

// Season year helpers
$currentYear = (int) now()->year;
$fallSeasonYear = now()->month <= 2 ? $currentYear - 1 : $currentYear;

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
$attachCommandHeartbeat = function ($event, string $command, string $sourceName): void {
    $service = app(CommandHeartbeatService::class);
    $sport = $service->inferSportFromCommand($command);

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
) use ($heartbeatUrl, $attachCommandHeartbeat) {
    $resolvedCommand = "{$command} ".date('Ymd');

    $event = Schedule::command($resolvedCommand)
        ->everyFiveMinutes()
        ->between($betweenStart, $betweenEnd)
        ->when($inSeason)
        ->name($name)
        ->withoutOverlapping()
        ->runInBackground();

    if ($heartbeatUrl) {
        $event->pingOnSuccess($heartbeatUrl);
    }

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

$schedulePredictionPipeline = function (
    string $sportCommandPrefix,
    string $sportLabel,
    int $season,
    callable $inSeason,
    array $times
) use ($scheduleDailySeasonJob) {
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
    ?string $playerPropsName = null
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
        $predictionTimes
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
    $currentYear,
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
    14,
    18,
    'NBA: Sync Player Props'
);

// CBB
$cbbTeamSchedulesEvent = Schedule::command('espn:sync-cbb-all-team-schedules')
    ->weeklyOn(0, '01:30')
    ->when($cbbInSeason)
    ->name('CBB: Sync All Team Schedules')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($cbbTeamSchedulesEvent, 'espn:sync-cbb-all-team-schedules', 'CBB: Sync All Team Schedules');

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
    $currentYear,
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

$dailyDigestsEvent = Schedule::command('alerts:send-daily-digests --sport=all')
    ->hourly()
    ->between('06:00', '22:00')
    ->name('Alerts: Send Daily Digests')
    ->withoutOverlapping()
    ->runInBackground();
$attachCommandHeartbeat($dailyDigestsEvent, 'alerts:send-daily-digests --sport=all', 'Alerts: Send Daily Digests');

// WCBB
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
    $currentYear,
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
    'MLB: Sync Player Props'
);

// WNBA
$scheduleSportPipeline(
    'espn:sync-wnba-current',
    '01:00',
    'WNBA: Sync Current Week',
    'espn:sync-wnba-games-scoreboard',
    '19:00',
    '23:00',
    'WNBA: Live Scoreboard Sync',
    null,
    null,
    null,
    null,
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
    'WNBA: Sync Odds'
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
    'CFB: Sync Odds'
);
