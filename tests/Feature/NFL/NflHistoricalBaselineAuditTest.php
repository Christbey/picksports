<?php

use Audit\NflHistoricalBaselineAudit;

require_once dirname(__DIR__, 3).'/scripts/audits/NflHistoricalBaselineAudit.php';

it('grades source-normalized handicaps and preserves pushes and no picks', function () {
    expect(NflHistoricalBaselineAudit::grade(2.3, -6.5, 3.0))->toBe('win')
        ->and(NflHistoricalBaselineAudit::grade(2.3, -3.0, 3.0))->toBe('push')
        ->and(NflHistoricalBaselineAudit::grade(3.04, -3.0, 7.0))->toBe('no_pick')
        ->and(NflHistoricalBaselineAudit::grade(3.0, null, 7.0))->toBeNull();
});

it('does not allow later scores to change prior simulated forecasts', function () {
    $games = [
        ['id' => 1, 'date' => '2020-09-01', 'season' => 2020, 'type' => '2', 'week' => 1, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 7.0],
        ['id' => 2, 'date' => '2020-09-08', 'season' => 2020, 'type' => '2', 'week' => 2, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => -7.0],
    ];
    $before = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'));
    $games[1]['actual'] = 100.0;
    $after = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'));
    expect(array_column($after, 'sequential_baseline'))->toBe(array_column($before, 'sequential_baseline'))
        ->and($before[0]['elo_difference'])->toBe(0)
        ->and($before[1]['elo_difference'])->toBeGreaterThan(0);
    $games[0]['actual'] = 0.0;
    $tied = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'));
    expect($tied[1]['elo_difference'])->toBe(0.0);
});

it('freezes same-date forecasts and regresses between seasons', function () {
    $games = [
        ['id' => 1, 'date' => '2020-09-01', 'season' => 2020, 'type' => '2', 'week' => 1, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 7.0],
        ['id' => 2, 'date' => '2020-09-01', 'season' => 2020, 'type' => '2', 'week' => 1, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 7.0],
        ['id' => 3, 'date' => '2021-09-01', 'season' => 2021, 'type' => '2', 'week' => 1, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 7.0],
    ];
    $normal = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'));
    $noRegression = NflHistoricalBaselineAudit::simulate($games, array_replace(config('nfl.elo'), ['offseason_regression_factor' => 0]));
    expect($normal[0]['sequential_baseline'])->toBe($normal[1]['sequential_baseline'])
        ->and($normal[2]['elo_difference'])->toBeLessThan($noRegression[2]['elo_difference']);
});

it('fits only supplied training outcomes with bounded simple mappings', function () {
    $rows = array_map(fn ($d) => ['elo_difference' => $d, 'neutral' => false, 'actual' => $d * 0.04 + 2], [-100, -50, 0, 50, 100]);
    $fit = NflHistoricalBaselineAudit::fit($rows);
    expect($fit['slope'])->toBe(0.04)->and($fit['home_points'])->toBe(2.0)->and($fit['training_mae'])->toBe(0.0);
    expect(NflHistoricalBaselineAudit::margin(10000, false))->toBe(15.0)
        ->and(NflHistoricalBaselineAudit::margin(0, true))->toBe(0.0);
});
