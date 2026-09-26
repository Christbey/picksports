<?php

use App\Services\NFL\NflQuarterbackReturnStudy;

function qbReturnGames(array $qbs): array
{
    return array_map(fn ($qb, $i) => ['id' => $i + 1, 'date' => sprintf('2025-09-%02d', $i + 1), 'season' => 2025,
        'home_team_id' => 1, 'away_team_id' => 2, 'home_qb_id' => $qb, 'away_qb_id' => 'opponent',
        'home_qb_name' => $qb, 'away_qb_name' => 'Opponent', 'home_score' => 21, 'away_score' => 20, 'home_line' => -3.5], $qbs, array_keys($qbs));
}

it('grades one return event not all later starts and never calls a gap an injury', function () {
    $r = (new NflQuarterbackReturnStudy)->analyze(qbReturnGames(['A', 'A', 'A', 'A', 'B', 'B', 'A', 'A']));
    expect($r['all'])->toMatchArray(['events' => 1, 'su_wins' => 1, 'ats_losses' => 1])
        ->and($r['events'][0]['missed_games'])->toBe(2)->and($r['events'][0]['injury_verified'])->toBeFalse()
        ->and($r['affects_prediction'])->toBeFalse();
});

it('excludes uncertain starter gaps and unestablished replacements', function ($qbs) {
    expect((new NflQuarterbackReturnStudy)->analyze(qbReturnGames($qbs))['all']['events'])->toBe(0);
})->with([
    [['A', 'A', 'A', 'A', null, 'A']],
    [['A', 'A', 'B', 'A']],
    [['A', 'A', 'A', 'A', 'A']],
]);

it('separates pushes missing lines and cross-season returns', function () {
    $games = qbReturnGames(['A', 'A', 'A', 'A', 'B', 'A']);
    $games[5]['home_line'] = -1.0;
    $games[5]['season'] = 2026;
    $games[5]['date'] = '2026-09-01';
    $r = (new NflQuarterbackReturnStudy)->analyze($games);
    expect($r['cross_season']['ats_pushes'])->toBe(1);
    $games[5]['home_line'] = null;
    expect((new NflQuarterbackReturnStudy)->analyze($games)['all']['missing_line'])->toBe(1);
});
