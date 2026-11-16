<?php

use App\Services\NFL\NflPlayoffElimination;

it('accounts for shared games and keeps a qualifying tie open', function () {
    $teams = [];
    foreach ([1 => 'East', 2 => 'East', 3 => 'West', 4 => 'West', 5 => 'West', 6 => 'North', 7 => 'North', 8 => 'North'] as $id => $division) {
        $teams[] = ['team_id' => $id, 'conference' => 'AFC', 'division' => $division];
    }
    $teams[] = ['team_id' => 9, 'conference' => 'NFC', 'division' => 'East'];
    $elimination = new NflPlayoffElimination;
    $win = fn (int $id): array => ['home' => $id, 'away' => 9, 'completed' => true, 'home_score' => 7, 'away_score' => 0];
    $tie = fn (int $id): array => ['home' => $id, 'away' => 9, 'completed' => true, 'home_score' => 7, 'away_score' => 7];
    $sharedGame = ['home' => 7, 'away' => 8, 'completed' => false, 'home_score' => 7, 'away_score' => 0];

    $games = [$win(1), $win(2), $win(2), $win(3), $win(3), $win(4), $win(4), $win(5), $win(5), $win(6), $win(6)];

    // West already takes two wild cards. Either North rival finishing ahead
    // takes the third, so both must finish with at most the target's record.
    expect($elimination->eliminatedTeams($teams, [...$games, $win(7), $win(8), $sharedGame]))->toContain(1)
        ->and($elimination->eliminatedTeams($teams, [...$games, $tie(7), $tie(8), $sharedGame]))->not->toContain(1)
        ->and($elimination->eliminatedTeams($teams, [...$games, $win(7), $win(8), $sharedGame, ['home' => 1, 'away' => 2, 'completed' => false]]))->not->toContain(1);
});
