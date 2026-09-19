<?php

use App\Services\CFB\Live\LiveScoreProjector;

test('regulation score projections remain coherent across scores and clocks', function () {
    $model = new LiveScoreProjector;
    foreach ([0, 1, 60, 900, 1800, 2700, 3599, 3600] as $remaining) {
        foreach ([[0, 0], [0, 35], [35, 0], [56, 49], [7, 14]] as [$home, $away]) {
            foreach ([-40, 0, 40] as $prior) {
                $p = $model->project($home, $away, $remaining, $prior, 56);
                expect($p['home_points'])->toBeGreaterThanOrEqual($home)
                    ->and($p['away_points'])->toBeGreaterThanOrEqual($away)
                    ->and($p['spread'])->toEqualWithDelta($p['home_points'] - $p['away_points'], .00001)
                    ->and($p['total'])->toEqualWithDelta($p['home_points'] + $p['away_points'], .00001)
                    ->and($p['home_win_probability'])->toBeGreaterThan(0)->toBeLessThan(1);
                $reverse = $model->project($away, $home, $remaining, -$prior, 56);
                expect($p['spread'])->toEqualWithDelta(-$reverse['spread'], .00001)
                    ->and($p['home_win_probability'] + $reverse['home_win_probability'])->toEqualWithDelta(1, .00001);
            }
        }
    }
});

test('starts at the pregame baseline and converges to the score without a 100 point cap', function () {
    $model = new LiveScoreProjector;
    $start = $model->project(0, 0, 3600, 14, 56);
    expect($start['spread'])->toBe(14.0)->and($start['total'])->toBe(56.0);
    $end = $model->project(56, 49, 0, 14, 56);
    expect($end['spread'])->toBe(7.0)->and($end['total'])->toBe(105.0);
    expect($model->project(56, 49, 900, 14, 56)['total'])->toBeGreaterThan(105);
});

test('late leads become more decisive and probability direction agrees with margin', function () {
    $model = new LiveScoreProjector;
    $early = $model->project(14, 7, 2700, 0, 56);
    $late = $model->project(14, 7, 60, 0, 56);
    expect($late['home_win_probability'])->toBeGreaterThan($early['home_win_probability']);
    $p = $model->project(13, 21, 1800, 0, 56);
    expect($p['spread'])->toBeLessThan(0)->and($p['home_win_probability'])->toBeLessThan(.5);
});
