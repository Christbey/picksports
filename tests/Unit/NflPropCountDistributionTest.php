<?php

use App\Services\BettingRecommendations\NflPropCountDistribution;

it('does not turn a low reception mean into a likely over by truncating a wide bell curve', function () {
    $distribution = new NflPropCountDistribution;
    $result = $distribution->outcomes(1.182, 1.5, 2.16);
    expect($result['over'])->toBeLessThan(0.5)
        ->and($result['push'])->toBe(0.0)
        ->and(array_sum($result))->toEqualWithDelta(1, 0.000001);
});

it('preserves count expectation and allocates exact integer push probabilities', function () {
    $distribution = new NflPropCountDistribution;
    $expectation = 0.0;
    for ($k = 0; $k <= 250; $k++) {
        $expectation += $k * $distribution->outcomes(1.182, $k, 2.16)['push'];
    }
    expect($expectation)->toEqualWithDelta(1.182, 0.0001);
    $poisson = $distribution->outcomes(2, 2, sqrt(2));
    expect($poisson['push'])->toEqualWithDelta(2 * exp(-2), 0.000001);
});

it('handles zero counts and the binary anytime touchdown outcome', function () {
    $distribution = new NflPropCountDistribution;
    expect($distribution->outcomes(0, 0.5, 2)['over'])->toBe(0.0);
    $half = $distribution->outcomes(1, 0.5, 1, true);
    $one = $distribution->outcomes(1, 1, 1, true);
    expect($half['over'])->toEqualWithDelta(1 - exp(-1), 0.000001)
        ->and($one['over'])->toBe(0.0)
        ->and($one['push'])->toEqualWithDelta($half['over'], 0.000001);
});
