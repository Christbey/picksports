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
