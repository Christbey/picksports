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

it('recognizes stored database venue flags without accepting null or arbitrary values', function (mixed $flag, int $expected) {
    $games = [];
    foreach ([1, 2, 3, 4, 5] as $week) {
        $games[] = situationalGame($week, now()->setDate(2026, 9, 6)->addWeeks($week - 1)->toDateString(), 7, $week < 5,
            ['neutral_site' => $flag]);
    }
    expect(situationalRecords($games)['road_after_home_heavy']['sample_size'])->toBe($expected);
})->with([[0, 1], ['0', 1], [false, 1], [1, 0], ['1', 0], [true, 0], [null, 0], ['unknown', 0], [2, 0]]);

it('counts international games and their next game including verified byes with source evidence', function (int $week, string $date, ?int $rest, int $expected) {
    $games = [situationalGame(1, '2026-09-06', 7, true, ['neutral_site' => true]),
        situationalGame($week, $date, -3, true, ['home_rest' => $rest])];
    $markets = [1 => ['source' => 'nflverse_schedule_verified', 'evidence_id' => 99,
        'international' => ['source' => 'nfl_official_international_schedule', 'country' => 'GB', 'stadium' => 'Wembley Stadium']]];
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], collect($games), $markets)['records'])->keyBy('id');
    expect($records['in_international']['sample_size'])->toBe(1)
        ->and($records['in_international']['record']['wins'])->toBe(1)
        ->and($records['in_international']['venue_evidence'][0]['schedule_evidence_id'])->toBe(99)
        ->and($records['after_international']['sample_size'])->toBe($expected)
        ->and($records['after_international']['record']['losses'])->toBe($expected);
    if ($expected) {
        expect($records['after_international']['venue_evidence'][0]['venue_game_id'])->toBe(1);
    }
})->with([[2, '2026-09-13', 7, 1], [3, '2026-09-20', 14, 1], [3, '2026-09-20', null, 0],
    [3, '2026-09-20', 7, 0], [4, '2026-09-27', 21, 0]]);

it('never classifies neutral games as international without official evidence', function () {
    $records = situationalRecords([situationalGame(1, '2026-09-06', 7, true, ['neutral_site' => true]), situationalGame(2, '2026-09-13')]);
    expect($records['in_international']['sample_size'])->toBe(0)
        ->and($records['after_international']['sample_size'])->toBe(0);
});

function roofScheduleEvidence(string $roof, string $stadium = 'HOME', array $overrides = []): array
{
    return array_replace(['source' => 'nflverse_schedule_verified', 'evidence_id' => 88, 'source_sha256' => 'test-source',
        'venue' => ['stadium_id' => $stadium, 'roof' => $roof, 'location' => 'Home']], $overrides);
}

it('classifies roof exposure from earlier home evidence and retains both source games', function (string $homeRoof, string $gameRoof, string $rule) {
    $games = collect([situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13', -3, false)]);
    $markets = [1 => roofScheduleEvidence($homeRoof), 2 => roofScheduleEvidence($gameRoof, 'AWAY')];
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games, $markets)['records'])->keyBy('id');
    expect($records[$rule]['sample_size'])->toBe(1)
        ->and($records[$rule]['record']['losses'])->toBe(1)
        ->and($records[$rule]['roof_evidence'][0]['home_venue']['game_id'])->toBe(1)
        ->and($records[$rule]['roof_evidence'][0]['game_venue']['game_id'])->toBe(2)
        ->and($records[$rule]['roof_evidence'][0]['home_venue']['schedule_evidence_id'])->toBe(88);
})->with([
    ['dome', 'outdoors', 'roofed_team_outdoors'], ['closed', 'outdoors', 'roofed_team_outdoors'],
    ['open', 'outdoors', 'roofed_team_outdoors'], ['dome', 'open', 'roofed_team_outdoors'],
    ['outdoors', 'dome', 'outdoor_team_indoors'], ['outdoors', 'closed', 'outdoor_team_indoors'],
]);

it('holds roof classifications with missing conflicting or future home evidence', function (string $fault) {
    $games = [situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13', 7, false)];
    $markets = [1 => roofScheduleEvidence('dome'), 2 => roofScheduleEvidence('outdoors', 'AWAY')];
    switch ($fault) {
        case 'missing home venue': unset($markets[1]);
            break;
        case 'missing game roof': $markets[2]['venue']['roof'] = null;
            break;
        case 'unknown home roof': $markets[1]['venue']['roof'] = 'unknown';
            break;
        case 'unverified source': $markets[1]['source'] = 'espn';
            break;
        case 'missing stadium': $markets[1]['venue']['stadium_id'] = '';
            break;
        case 'neutral home': $games[0]->neutral_site = 1;
            break;
        case 'unknown home location': $games[0]->neutral_site = null;
            break;
        case 'provider neutral home': $markets[1]['venue']['location'] = 'Neutral';
            break;
        case 'international home': $markets[1]['international'] = ['source' => 'nfl_official_international_schedule'];
            break;
        case 'international target': $markets[2]['international'] = ['source' => 'nfl_official_international_schedule'];
            break;
        case 'season boundary': $games[0]->season = 2025;
            break;
        case 'unfinished home': $games[0]->status = 'STATUS_SCHEDULED';
            break;
        case 'unknown kickoff': $games[0]->game_time = null;
            break;
        case 'future home': $games[0]->game_date = '2026-09-20';
            break;
        case 'same kickoff': $games[0]->game_date = '2026-09-13';
            break;
        case 'new home stadium': $games[1]->home_team_id = 1;
            $games[1]->away_team_id = 2;
            break;
        case 'covered target': $markets[2]['venue']['roof'] = 'closed';
            break;
    }
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], collect($games), $markets)['records'])->keyBy('id');
    expect($records['roofed_team_outdoors']['sample_size'])->toBe(0)
        ->and($records['outdoor_team_indoors']['sample_size'])->toBe(0);
})->with(['missing home venue', 'missing game roof', 'unknown home roof', 'unverified source', 'missing stadium',
    'neutral home', 'unknown home location', 'provider neutral home', 'international home', 'international target',
    'season boundary', 'unfinished home', 'unknown kickoff', 'future home', 'same kickoff', 'new home stadium', 'covered target']);

it('clears an old home profile when a later home game has no verified venue', function () {
    $games = collect([situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13'), situationalGame(3, '2026-09-20', 7, false)]);
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games,
        [1 => roofScheduleEvidence('dome'), 3 => roofScheduleEvidence('outdoors', 'AWAY')])['records'])->keyBy('id');
    expect($records['roofed_team_outdoors']['sample_size'])->toBe(0);
});

it('evaluates home-base travel records with distance direction and kickoff boundaries', function (string $origin, string $destination, string $time, array $expected) {
    $games = collect([situationalGame(1, '2026-09-06'), situationalGame(2, '2026-09-13', 7, false, ['game_time' => $time])]);
    $markets = [1 => roofScheduleEvidence('outdoors', $origin), 2 => roofScheduleEvidence('outdoors', $destination)];
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], $games, $markets)['records'])->keyBy('id');
    foreach (['distance_1000', 'distance_2000', 'clock_one', 'clock_two_plus', 'eastward_clock', 'eastward_early', 'westward_clock'] as $id) {
        expect($records[$id]['sample_size'])->toBe(in_array($id, $expected, true) ? 1 : 0);
        if ($records[$id]['sample_size']) {
            expect($records[$id]['travel_evidence'][0]['home_venue']['game_id'])->toBe(1)
                ->and($records[$id]['travel_evidence'][0]['game_venue']['game_id'])->toBe(2)
                ->and($records[$id]['travel_evidence'][0]['mode'])->toBe('home_base_comparison');
        }
    }
})->with([
    ['LAX01', 'NYC01', '17:00:00', ['distance_1000', 'distance_2000', 'clock_two_plus', 'eastward_clock', 'eastward_early']],
    ['LAX01', 'NYC01', '16:00:00', ['distance_1000', 'distance_2000', 'clock_two_plus', 'eastward_clock', 'eastward_early']],
    ['LAX01', 'NYC01', '18:00:00', ['distance_1000', 'distance_2000', 'clock_two_plus', 'eastward_clock']],
    ['LAX01', 'NYC01', '15:00:00', ['distance_1000', 'distance_2000', 'clock_two_plus', 'eastward_clock']],
    ['NYC01', 'LAX01', '20:00:00', ['distance_1000', 'distance_2000', 'clock_two_plus', 'westward_clock']],
    ['CHI98', 'NYC01', '17:00:00', ['clock_one', 'eastward_clock', 'eastward_early']],
    ['LAX01', 'KAN00', '17:00:00', ['distance_1000', 'clock_two_plus', 'eastward_clock', 'eastward_early']],
    ['LAX01', 'DEN00', '17:00:00', ['clock_one', 'eastward_clock']],
    ['NYC01', 'NYC01', '17:00:00', []],
    ['UNKNOWN', 'NYC01', '17:00:00', []],
]);

it('requires earlier same-season home evidence before evaluating travel', function (string $fault) {
    $home = situationalGame(1, '2026-09-06');
    $away = situationalGame(2, '2026-09-13', 7, false);
    $markets = [1 => roofScheduleEvidence('dome', 'LAX01'), 2 => roofScheduleEvidence('outdoors', 'NYC01')];
    if ($fault === 'missing') {
        unset($markets[1]);
    } elseif ($fault === 'future') {
        $home->game_date = '2026-09-20';
    } elseif ($fault === 'prior season') {
        $home->season = 2025;
    } elseif ($fault === 'international') {
        $markets[2]['international'] = ['source' => 'nfl_official_international_schedule'];
    }
    $records = collect(app(NflSituationalRecordService::class)->build((object) ['id' => 1], collect([$home, $away]), $markets)['records']);
    expect($records->whereBetween('catalog_id', [336, 342])->sum('sample_size'))->toBe(0);
})->with(['missing', 'future', 'prior season', 'international']);
