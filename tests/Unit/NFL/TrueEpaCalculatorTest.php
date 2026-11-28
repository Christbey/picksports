<?php

use App\Services\Epa\StateBaselineService;
use App\Services\NFL\PlayEpaDataService;
use App\Services\NFL\TrueEpaCalculator;
use Illuminate\Support\Collection;

function nflEpaPlay(int $id, array $attributes = []): object
{
    return (object) array_merge([
        'id' => $id, 'period' => 1, 'play_type' => 'Rush',
        'play_text' => '', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 70,
        'possession_team_id' => 10, 'home_score' => 0, 'away_score' => 0,
        'is_scoring_play' => false,
    ], $attributes);
}

function nflEpaCalculate(array $plays): array
{
    return (new TrueEpaCalculator(new PlayEpaDataService, new StateBaselineService))
        ->calculateForGame(new Collection($plays), 10, 20);
}

it('credits a touchdown even when a preceding timeout already carries its score', function () {
    $results = nflEpaCalculate([
        nflEpaPlay(1, ['play_type' => 'Official Timeout', 'home_score' => 7]),
        nflEpaPlay(2, ['play_type' => 'Passing Touchdown', 'is_scoring_play' => true, 'home_score' => 7]),
    ]);

    expect($results[1]['eligible'])->toBeFalse()
        ->and($results[2]['ep_before'])->toBe(7.0)
        ->and($results[2]['epa'])->toBe(0.0);
});

it('credits scoring once and uses cumulative scoring events rather than adjacent snapshots', function () {
    $results = nflEpaCalculate([
        nflEpaPlay(1, ['is_scoring_play' => true, 'home_score' => 7]),
        nflEpaPlay(2, ['play_type' => 'Official Timeout', 'home_score' => 7, 'away_score' => 3]),
        nflEpaPlay(3, ['is_scoring_play' => true, 'play_type' => 'Field Goal Good', 'possession_team_id' => 20, 'home_score' => 7, 'away_score' => 3]),
    ]);

    foreach ([1 => 7.0, 3 => 3.0] as $id => $points) {
        expect(round($results[$id]['epa'] - $results[$id]['ep_after'] + $results[$id]['ep_before'], 3))->toBe($points);
    }
});

it('attributes defensive touchdowns and safeties against the possessing offense', function (int $points) {
    $results = nflEpaCalculate([nflEpaPlay(1, ['is_scoring_play' => true, 'away_score' => $points])]);

    expect($results[1]['ep_before'])->toBe((float) -$points)
        ->and(round($results[1]['epa'] + $results[1]['ep_before'], 3))->toBe((float) -$points);
})->with([2, 7]);

it('terminates expected points and future scoring at halftime and regulation', function (int $before, int $after) {
    $results = nflEpaCalculate([
        nflEpaPlay(1, ['period' => $before]),
        nflEpaPlay(2, ['period' => $after, 'is_scoring_play' => true, 'home_score' => 7, 'yards_to_endzone' => 5]),
    ]);

    expect($results[1]['ep_before'])->toBe(0.0)
        ->and($results[1]['ep_after'])->toBe(0.0)
        ->and($results[1]['epa'])->toBe(0.0);
})->with([[2, 3], [4, 5]]);

it('retains continuation value across first and third quarter breaks', function (int $before, int $after) {
    $results = nflEpaCalculate([
        nflEpaPlay(1, ['period' => $before]),
        nflEpaPlay(2, ['period' => $after, 'is_scoring_play' => true, 'home_score' => 7, 'yards_to_endzone' => 5]),
    ]);

    expect($results[1]['ep_before'])->toBe(7.0)
        ->and($results[1]['ep_after'])->toBe(7.0);
})->with([[1, 2], [3, 4]]);
