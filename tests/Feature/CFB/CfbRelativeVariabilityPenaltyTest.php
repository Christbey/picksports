<?php

use App\Services\CFB\CfbRelativeVariabilityPenalty;

it('scales variability with yardage rather than applying the same absolute cutoff', function () {
    $policy = app(CfbRelativeVariabilityPenalty::class);
    $passing = $policy->assess(286, ['std_dev' => 32.2], 6);
    $scaled = $policy->assess(28.6, ['std_dev' => 3.22], 6);
    expect($passing['penalty'])->toBe(1)->and($scaled['penalty'])->toBe(1)
        ->and($passing['sample_penalty'])->toBe(0);
});

it('uses the current season sample and bounds uncertainty', function () {
    $policy = app(CfbRelativeVariabilityPenalty::class);
    expect($policy->assess(10, ['std_dev' => 100], 0)['penalty'])->toBe(14)
        ->and($policy->assess(286, ['std_dev' => 32.2], 3)['penalty'])->toBe(4);
});

it('retains the conservative fallback when relative variability cannot be computed', function ($mean, $std) {
    expect(app(CfbRelativeVariabilityPenalty::class)->assess($mean, ['std_dev' => $std], 6)['applied'])->toBeFalse();
})->with([[0, 10], [-1, 10], [100, null], [100, -1]]);
