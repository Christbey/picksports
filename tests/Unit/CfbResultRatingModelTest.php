<?php

use App\Services\CFB\Ratings\ResultRatingModel;

function cfbRatingGame(int $id, int $home, int $away, int $homeScore, int $awayScore, int $season = 2025): array
{
    return ['id' => $id, 'season' => $season, 'home_team_id' => $home, 'away_team_id' => $away,
        'home_score' => $homeScore, 'away_score' => $awayScore, 'neutral_site' => true];
}

test('rates prior-only teams through common opponents without market information', function () {
    $service = new ResultRatingModel;
    $games = [cfbRatingGame(1, 1, 2, 35, 7), cfbRatingGame(2, 2, 3, 28, 14), cfbRatingGame(3, 1, 3, 42, 7)];
    $fit = $service->fit($games, 2026);
    $forecast = $service->predict($fit, 1, 3, true);
    expect($forecast['home_margin'])->toBeGreaterThan(0)->and($forecast['home']['current_games'])->toBe(0)
        ->and($forecast['home']['prior_games'])->toBe(2)->and($forecast['away']['source_game_ids'])->toBe([2, 3]);
    $reverse = $service->predict($fit, 3, 1, true);
    expect($forecast['home_margin'])->toEqual(-$reverse['home_margin'])->and($forecast['total'])->toEqual($reverse['total']);
    $changedOdds = array_map(fn ($g) => [...$g, 'vegas_spread' => -99], $games);
    expect($service->fit($changedOdds, 2026))->toBe($fit);
});

test('does not invent ratings for unknown teams or disconnected opponent networks', function () {
    $service = new ResultRatingModel;
    $fit = $service->fit([cfbRatingGame(1, 1, 2, 21, 7), cfbRatingGame(2, 3, 4, 21, 7)], 2026);
    expect($service->predict($fit, 1, 3))->toBeNull()->and($service->predict($fit, 1, 99))->toBeNull();
});

test('additional consistent results reduce the shrinkage of a tiny sample', function () {
    $service = new ResultRatingModel;
    $one = $service->predict($service->fit([cfbRatingGame(1, 1, 2, 35, 7, 2026)], 2026), 1, 2, true);
    $many = [];
    for ($i = 1; $i <= 10; $i++) {
        $many[] = cfbRatingGame($i, 1, 2, 35, 7, 2026);
    }
    $ten = $service->predict($service->fit($many, 2026), 1, 2, true);
    expect($one['home_margin'])->toBeGreaterThan(0)->toBeLessThan(28)
        ->and($ten['home_margin'])->toBeGreaterThan($one['home_margin'])->toBeLessThanOrEqual(28);
});

test('invalid or duplicate result rows cannot manufacture independent rating evidence', function () {
    $service = new ResultRatingModel;
    $valid = cfbRatingGame(1, 1, 2, 35, 7, 2026);
    $bad = [
        [...$valid, 'id' => 2, 'home_score' => INF],
        [...$valid, 'id' => 3, 'home_score' => 35.5],
        [...$valid, 'id' => 4, 'home_team_id' => '2'],
        [...$valid, 'id' => 5, 'away_team_id' => 0],
        [...$valid, 'id' => 6, 'home_score' => 7],
        [...$valid, 'id' => 7, 'away_score' => -1],
    ];
    expect($service->fit([$valid, $valid, ...$bad], 2026))->toBe($service->fit([$valid], 2026))
        ->and($service->fit($bad, 2026)['status'])->toBe('missing_results');
});
