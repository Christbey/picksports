<?php

use App\Models\NFL\Game;
use App\Services\NFL\NflSituationalRecordService;
use Illuminate\Support\Facades\DB;

function situationalGame(int $week, string $date, int $margin = 7, bool $home = true, array $overrides = []): Game
{
    $game = new Game(array_merge([
        'id' => $week, 'season' => 2026, 'season_type' => 2, 'week' => $week,
        'home_team_id' => $home ? 1 : 2, 'away_team_id' => $home ? 2 : 1,
        'home_score' => $home ? 30 + $margin : 30, 'away_score' => $home ? 30 : 30 + $margin,
        'game_date' => $date, 'game_time' => '17:00:00', 'status' => 'STATUS_FINAL', 'neutral_site' => false,
    ], $overrides));
    $game->id = $week;
    $game->setRelation('teamStats', collect());

    return $game;
}

function situationalRecords(array $games): array
{
    return collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], collect($games))['records'])->keyBy('id')->all();
}

it('reports home road and ties with evidence dates and minimum sample without queries or stats', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $records = situationalRecords([
        situationalGame(4, '2026-09-27', -7, false),
        situationalGame(3, '2026-09-20', 0),
        situationalGame(2, '2026-09-13', -7),
        situationalGame(1, '2026-09-06', 7),
        situationalGame(5, '2026-10-04', 7, true, ['neutral_site' => true]),
    ]);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    expect($records['home']['record'])->toBe(['wins' => 1, 'losses' => 1, 'ties' => 1])
        ->and($records['home']['sample_size'])->toBe(3)
        ->and($records['home']['status'])->toBe('descriptive_record')
        ->and($records['home']['from_date'])->toBe('2026-09-06')
        ->and($records['home']['through_date'])->toBe('2026-09-20')
        ->and($records['road']['sample_size'])->toBe(1)
        ->and($records['road']['status'])->toBe('insufficient_data');
});

it('uses Eastern kickoff weekdays and refuses date-only midnight guesses', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-11', 7, true, ['game_time' => '00:20:00']),
        situationalGame(2, '2026-09-18', 7, true, ['game_time' => '00:20:00']),
        situationalGame(3, '2026-09-24', 7, true, ['game_time' => null]),
    ]);
    expect($records['thursday']['sample_size'])->toBe(2)
        ->and($records['thursday']['from_date'])->toBe('2026-09-10')
        ->and($records['thursday']['through_date'])->toBe('2026-09-17');
});

it('classifies prior margins without treating a tie or missing score as a loss', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-06', 21),
        situationalGame(2, '2026-09-13', -21),
        situationalGame(3, '2026-09-20', 8),
        situationalGame(4, '2026-09-27', -8),
        situationalGame(5, '2026-10-04', 0),
        situationalGame(6, '2026-10-11', 7, true, ['home_score' => null]),
        situationalGame(7, '2026-10-18', 7),
    ]);
    expect($records['after_blowout_win']['record']['losses'])->toBe(1)
        ->and($records['after_blowout_loss']['record']['wins'])->toBe(1)
        ->and($records['after_one_score_win']['record']['losses'])->toBe(1)
        ->and($records['after_one_score_loss']['record']['ties'])->toBe(1)
        ->and($records['after_loss']['sample_size'])->toBe(2)
        ->and($records['home']['sample_size'])->toBe(6);
});

it('separates exact two win streaks from three plus and ties break streaks', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13'),
        situationalGame(3, '2026-09-20'), situationalGame(4, '2026-09-27', 0),
        situationalGame(5, '2026-10-04', -7), situationalGame(6, '2026-10-11', -7),
        situationalGame(7, '2026-10-18', -7), situationalGame(8, '2026-10-25'),
    ]);
    expect($records['after_two_wins']['sample_size'])->toBe(1)
        ->and($records['after_three_wins']['record']['ties'])->toBe(1)
        ->and($records['after_two_losses']['record']['losses'])->toBe(1)
        ->and($records['after_three_losses']['record']['wins'])->toBe(1);
});

it('never joins season boundaries offseason gaps or missing schedule weeks', function () {
    $records = situationalRecords([
        situationalGame(17, '2025-12-28', 21, false, ['season' => 2025]),
        situationalGame(18, '2026-01-04', 21, false, ['season' => 2025]),
        situationalGame(1, '2026-09-06', 7, false),
        situationalGame(3, '2026-09-20', 7, false),
    ]);
    expect($records['after_win']['sample_size'])->toBe(1)
        ->and($records['after_two_wins']['sample_size'])->toBe(0)
        ->and($records['extended_rest']['sample_size'])->toBe(0)
        ->and($records['second_road']['sample_size'])->toBe(0);
});

it('tracks exact second and third road games and resets at a neutral site', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-06', 7, false), situationalGame(2, '2026-09-13', -7, false),
        situationalGame(3, '2026-09-20', 0, false), situationalGame(4, '2026-09-27', 7, false),
        situationalGame(5, '2026-10-04', 7, false, ['neutral_site' => true]),
        situationalGame(6, '2026-10-11', 7, false),
    ]);
    expect($records['second_road']['record']['losses'])->toBe(1)
        ->and($records['second_road']['sample_size'])->toBe(1)
        ->and($records['third_road']['record']['ties'])->toBe(1)
        ->and($records['third_road']['sample_size'])->toBe(1);
});

it('derives short rest and Thursday mini bye without claiming a verified bye', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-07', 7), // Monday.
        situationalGame(2, '2026-09-13', -7), // Sunday on six-day rest.
        situationalGame(3, '2026-09-18', 7, true, ['game_time' => '00:20:00']), // Thursday Eastern.
        situationalGame(4, '2026-09-27', 7),
    ]);
    expect($records['short_rest']['sample_size'])->toBe(2)
        ->and($records['sunday_after_monday']['record']['losses'])->toBe(1)
        ->and($records['after_thursday']['record']['wins'])->toBe(1)
        ->and($records['extended_rest']['sample_size'])->toBe(1);
    $result = app(NflSituationalRecordService::class)->build((object) ['id' => 1], collect());
    expect($result['market_records']['ats']['status'])->toBe('unavailable')
        ->and($result['market_records']['totals']['status'])->toBe('unavailable');
});

it('uses explicit rest overtime and division evidence and leaves absent inputs uncounted', function () {
    $records = situationalRecords([
        situationalGame(1, '2026-09-06', 7, true, ['division_game' => true, 'period' => 5]),
        situationalGame(2, '2026-09-13', 7, true, ['home_rest' => 11, 'away_rest' => 7, 'division_game' => false]),
        situationalGame(3, '2026-09-20', -3, true, ['division_game' => true, 'home_rest' => 6, 'away_rest' => 7]),
    ]);
    expect($records['rest_advantage_two']['sample_size'])->toBe(1)
        ->and($records['rest_advantage_four']['sample_size'])->toBe(1)
        ->and($records['rest_disadvantage']['sample_size'])->toBe(1)
        ->and($records['after_overtime']['sample_size'])->toBe(1)
        ->and($records['between_divisional']['sample_size'])->toBe(1)
        ->and($records['before_divisional']['sample_size'])->toBe(1)
        ->and($records['after_divisional']['sample_size'])->toBe(1);
});

it('grades archived closing handicaps with correct home-away signs and retains pushes', function () {
    $games = collect([
        situationalGame(1, '2026-09-06', 3, true),
        situationalGame(2, '2026-09-13', -3, false),
        situationalGame(3, '2026-09-20', 7, true),
        situationalGame(4, '2026-09-27', 7, false),
    ]);
    $markets = [1 => ['home_handicap' => -3, 'snapshot_id' => 1, 'mode' => 'retrospective_closing_line_record'],
        2 => ['home_handicap' => -7, 'snapshot_id' => 2, 'mode' => 'retrospective_closing_line_record'],
        3 => ['home_handicap' => -10, 'snapshot_id' => 3, 'mode' => 'retrospective_closing_line_record']];
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games, $markets)['records'])->keyBy('id');
    expect($records['home_ats']['record'])->toBe(['wins' => 0, 'losses' => 1, 'ties' => 1])
        ->and($records['road_ats']['record'])->toBe(['wins' => 1, 'losses' => 0, 'ties' => 0])
        ->and($records['road_ats']['sample_size'])->toBe(1)
        ->and($records['home_favorite']['sample_size'])->toBe(2)
        ->and($records['road_underdog']['sample_size'])->toBe(1)
        ->and($records['home_ats']['market_evidence'][0]['team_handicap'])->toBe(-3);
});

it('requires corroborated bye rest and grades totals without inventing missing lines', function () {
    $games = collect([
        situationalGame(1, '2026-09-06', 7),
        situationalGame(3, '2026-09-20', 7, true, ['home_rest' => 14, 'away_rest' => 14]),
        situationalGame(5, '2026-10-04', -7, true, ['home_rest' => null]),
        situationalGame(7, '2026-10-18', 0, true, ['home_rest' => 14]),
    ]);
    $markets = [3 => ['home_handicap' => -7, 'total' => 67], 7 => ['home_handicap' => 3, 'total' => 59]];
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games, $markets)['records'])->keyBy('id');
    expect($records['after_bye']['sample_size'])->toBe(2)
        ->and($records['before_bye']['sample_size'])->toBe(2)
        ->and($records['opponent_after_bye']['sample_size'])->toBe(1)
        ->and($records['after_bye_ats']['record'])->toBe(['wins' => 1, 'losses' => 0, 'ties' => 1])
        ->and($records['after_bye_total']['record'])->toBe(['wins' => 1, 'losses' => 0, 'ties' => 1]);
});

it('uses the prior games workload and keeps offense and defense separate', function () {
    $games = collect([situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13', -7), situationalGame(3, '2026-09-20')]);
    $workloads = [1 => [1 => ['snaps' => 70], 2 => ['snaps' => 69]], 2 => [1 => ['snaps' => 60], 2 => ['snaps' => 75]]];
    $rows = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games, [], $workloads)['records'])->keyBy('id');
    expect($rows['after_70_offensive_snaps']['record'])->toBe(['wins' => 0, 'losses' => 1, 'ties' => 0])
        ->and($rows['after_70_defensive_snaps']['record'])->toBe(['wins' => 1, 'losses' => 0, 'ties' => 0])
        ->and($rows['after_70_defensive_snaps']['workload_evidence'][0]['previous_game_id'])->toBe(2);
});

it('counts venue-heavy stretches from the prior four games and retains their evidence', function (bool $home) {
    $games = [];
    foreach ([1, 2, 3, 4, 5] as $week) {
        $games[] = situationalGame($week, now()->setDate(2026, 9, 6)->addWeeks($week - 1)->toDateString(),
            $week === 5 ? 0 : 7, $week === 5 ? $home : ! $home,
            $week === 2 ? ['neutral_site' => true] : []);
    }
    $id = $home ? 'home_after_road_heavy' : 'road_after_home_heavy';
    $other = $home ? 'road_after_home_heavy' : 'home_after_road_heavy';
    $records = situationalRecords(array_reverse($games));
    expect($records[$id]['sample_size'])->toBe(1)
        ->and($records[$id]['record'])->toBe(['wins' => 0, 'losses' => 0, 'ties' => 1])
        ->and($records[$id]['status'])->toBe('insufficient_data')
        ->and($records[$id]['schedule_evidence'])->toBe([[
            'game_id' => 5, 'previous_game_ids' => [1, 2, 3, 4],
            'home_games' => $home ? 0 : 3, 'road_games' => $home ? 3 : 0, 'neutral_games' => 1,
        ]])
        ->and($records[$other]['sample_size'])->toBe(0);
})->with([true, false]);

it('holds venue stretches when the previous four games cannot establish the condition', function (string $fault) {
    $games = [];
    foreach ([1, 2, 3, 4, 5] as $week) {
        $games[] = situationalGame($week, now()->setDate(2026, 9, 6)->addWeeks($week - 1)->toDateString(), 7, $week < 5);
    }
    switch ($fault) {
        case 'missing week':
            unset($games[1]);
            break;
        case 'week gap':
            $games[0]->week = 0;
            break;
        case 'season boundary':
            $games[0]->season = 2025;
            break;
        case 'unfinished':
            $games[1]->status = 'STATUS_SCHEDULED';
            break;
        case 'missing score':
            $games[1]->home_score = null;
            break;
        case 'unknown kickoff':
            $games[1]->game_time = null;
            break;
        case 'unknown venue':
            $games[1]->neutral_site = null;
            break;
        case 'neutral target':
            $games[4]->neutral_site = true;
            break;
        case 'unknown target venue':
            $games[4]->neutral_site = null;
            break;
        case 'only two of four':
            $games[0]->neutral_site = true;
            $games[1]->neutral_site = true;
            break;
        case 'long gap':
            $games[0]->game_date = '2026-08-01';
            break;
    }
    $records = situationalRecords($games);
    expect($records['road_after_home_heavy']['sample_size'])->toBe(0)
        ->and($records['home_after_road_heavy']['sample_size'])->toBe(0);
})->with(['missing week', 'week gap', 'season boundary', 'unfinished', 'missing score', 'unknown kickoff',
    'unknown venue', 'neutral target', 'unknown target venue', 'only two of four', 'long gap']);
