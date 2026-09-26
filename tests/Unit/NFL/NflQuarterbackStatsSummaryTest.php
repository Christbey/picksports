<?php

use App\Models\NFL\PlayerStat;
use App\Services\NFL\NflQuarterbackStatsSummary;

it('preserves every local career summary value including team transfers and integer coercion', function () {
    $rows = collect([
        new PlayerStat(['team_id' => 8, 'passing_attempts' => '31', 'passing_yards' => 243, 'passing_touchdowns' => 2, 'interceptions_thrown' => 1, 'sacks_taken' => 3, 'rushing_yards' => 17]),
        new PlayerStat(['team_id' => 12, 'passing_attempts' => 29, 'passing_yards' => 201, 'passing_touchdowns' => 1, 'interceptions_thrown' => null, 'sacks_taken' => 2, 'rushing_yards' => -3]),
        new PlayerStat(['team_id' => '12', 'passing_attempts' => 20, 'passing_yards' => '162.8', 'passing_touchdowns' => null, 'interceptions_thrown' => 2, 'sacks_taken' => null, 'rushing_yards' => null]),
    ]);

    expect((new NflQuarterbackStatsSummary)->summarize($rows, 12))->toBe([
        'games' => 3,
        'current_team_games' => 2,
        'attempts' => 80,
        'yards' => 606,
        'touchdowns' => 3,
        'interceptions' => 3,
        'sacks' => 5,
        'rush_yards' => 14,
        'yards_per_attempt' => 606 / 80,
        'td_rate' => 3 / 80,
        'int_rate' => 3 / 80,
        'sack_rate' => 5 / 85,
        'rush_yards_per_game' => 14 / 3,
    ]);
});

it('preserves nflverse zero-sack policy and omits an unsupported current-team count', function () {
    $rows = [
        (object) ['passing_attempts' => 30, 'passing_yards' => 250, 'passing_touchdowns' => 2, 'interceptions_thrown' => 1, 'rushing_yards' => 22, 'sacks_taken' => 0],
        (object) ['passing_attempts' => 20, 'passing_yards' => 150, 'passing_touchdowns' => 1, 'interceptions_thrown' => 0, 'rushing_yards' => -2, 'sacks_taken' => 0],
    ];

    expect((new NflQuarterbackStatsSummary)->summarize($rows))->toBe([
        'games' => 2,
        'attempts' => 50,
        'yards' => 400,
        'touchdowns' => 3,
        'interceptions' => 1,
        'sacks' => 0,
        'rush_yards' => 20,
        'yards_per_attempt' => 400 / 50,
        'td_rate' => 3 / 50,
        'int_rate' => 1 / 50,
        'sack_rate' => 0 / 50,
        'rush_yards_per_game' => 20 / 2,
    ]);
});

it('keeps the empty history contract without dividing by zero', function (?int $teamId) {
    expect((new NflQuarterbackStatsSummary)->summarize([], $teamId))->toBe([
        'games' => 0,
        ...($teamId !== null ? ['current_team_games' => 0] : []),
        'attempts' => 0,
        'yards' => 0,
        'touchdowns' => 0,
        'interceptions' => 0,
        'sacks' => 0,
        'rush_yards' => 0,
        'yards_per_attempt' => 0.0,
        'td_rate' => 0.0,
        'int_rate' => 0.0,
        'sack_rate' => 0.0,
        'rush_yards_per_game' => 0.0,
    ]);
})->with([null, 12]);

it('processes nullable projected rows in one traversal without altering rate precision', function () {
    $visits = 0;
    $rows = (function () use (&$visits) {
        $visits++;
        yield (object) ['passing_attempts' => 3, 'passing_yards' => 8, 'sacks_taken' => 1];
        $visits++;
        yield (object) ['passing_attempts' => null, 'passing_yards' => null];
    })();

    $summary = (new NflQuarterbackStatsSummary)->summarize($rows);

    expect($visits)->toBe(2)
        ->and($summary['games'])->toBe(2)
        ->and($summary['yards_per_attempt'])->toBe(8 / 3)
        ->and($summary['sack_rate'])->toBe(1 / 4)
        ->and($summary['interceptions'])->toBe(0);
});
