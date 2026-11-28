<?php

use App\Models\MLB\Game;
use App\Models\MLB\TeamStat;
use App\Support\MLB\MlbGameScoreResolver;

function mlbScoreObservationGame(mixed $home, mixed $away): Game
{
    return (new Game(['home_team_id' => 10, 'away_team_id' => 20, 'home_score' => $home, 'away_score' => $away]))
        ->setRelation('teamStats', collect());
}

test('mlb score reads preserve malformed historical values as unavailable', function (mixed $score) {
    $result = app(MlbGameScoreResolver::class)->resolve(mlbScoreObservationGame($score, 2));
    expect($result)->toBe(['home' => null, 'away' => 2, 'complete' => false, 'source' => 'unresolved']);
})->with([
    'negative' => [-1], 'fraction' => [1.5], 'fraction string' => ['1.5'],
    'exponent text' => ['1e2'], 'overflow' => ['2147483648'], 'nan' => [NAN], 'infinity' => [INF],
]);

test('mlb score reads preserve observed zero and legitimate whole numeric text', function () {
    $result = app(MlbGameScoreResolver::class)->resolve(mlbScoreObservationGame('0', '2.0'));
    expect($result)->toBe(['home' => 0, 'away' => 2, 'complete' => true, 'source' => 'mlb_games']);
});

test('mlb score reads use known team stat runs without coercing invalid fallback runs', function () {
    $game = mlbScoreObservationGame(null, 2)->setRelation('teamStats', collect([
        new TeamStat(['team_id' => 10, 'team_type' => 'home', 'runs' => '1.5']),
    ]));
    expect(app(MlbGameScoreResolver::class)->resolve($game)['complete'])->toBeFalse();
    $game->setRelation('teamStats', collect([new TeamStat(['team_id' => 10, 'team_type' => 'home', 'runs' => 0])]));
    expect(app(MlbGameScoreResolver::class)->resolve($game))->toBe(['home' => 0, 'away' => 2, 'complete' => true, 'source' => 'mixed']);
});

test('mlb score reads preserve observed team stat replacement for the legacy two zero placeholder', function () {
    $game = mlbScoreObservationGame(0, 0)->setRelation('teamStats', collect([
        new TeamStat(['team_id' => 10, 'team_type' => 'home', 'runs' => 3]),
        new TeamStat(['team_id' => 20, 'team_type' => 'away', 'runs' => 0]),
    ]));
    expect(app(MlbGameScoreResolver::class)->resolve($game))->toBe(['home' => 3, 'away' => 0, 'complete' => true, 'source' => 'mlb_team_stats']);
});

test('mlb score reads refuse a team outside the game instead of assigning the away score', function () {
    expect(app(MlbGameScoreResolver::class)->forTeam(mlbScoreObservationGame(3, 2), 30))->toBe([
        'team' => null, 'opponent' => null, 'complete' => false, 'source' => 'unresolved',
    ]);
});
