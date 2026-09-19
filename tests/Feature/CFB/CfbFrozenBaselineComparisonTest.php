<?php

use App\Actions\CFB\CalculateElo;
use App\Services\CFB\Predictions\CfbFrozenBaselineComparison;
use Carbon\CarbonImmutable;

function frozenCfbInputs(): array
{
    $team = ['elo' => 1500, 'metrics' => ['fpi' => 0],
        'elo_evidence' => ['qualified' => true, 'model_version' => CalculateElo::MODEL_VERSION, 'observed_at' => '2026-09-01T00:00:00Z'],
        'rating_evidence' => ['source' => 'cfbd_fpi', 'units' => 'points_above_average', 'season' => 2026, 'observed_at' => '2026-09-01T00:00:00Z']];

    return ['event' => ['season' => 2026, 'neutral_site' => true], 'home' => $team, 'away' => $team];
}

it('compares paired fixed baselines from observed pregame inputs without tuning to outcomes', function () {
    $inputs = frozenCfbInputs();
    $inputs['home']['elo'] = 1750;
    $inputs['home']['metrics']['fpi'] = 20;
    $service = new CfbFrozenBaselineComparison;
    $result = $service->evaluate($inputs, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-03'), 17);
    expect($result['margins'])->toBe(['fpi' => 20.0, 'corrected_elo' => 10.0, 'fixed_half_blend' => 15.0])
        ->and($result['errors'])->toBe(['fpi' => 3.0, 'corrected_elo' => -7.0, 'fixed_half_blend' => -2.0])
        ->and($service->summarize([3, -7]))->toBe(['n' => 2, 'mae' => 5.0, 'bias' => -2.0]);
});

it('excludes late snapshots and cannot label retroactively rebuilt ratings as historical pregame evidence', function () {
    $inputs = frozenCfbInputs();
    $service = new CfbFrozenBaselineComparison;
    $result = $service->evaluate($inputs, CarbonImmutable::parse('2026-09-03'), CarbonImmutable::parse('2026-09-03'), 17);
    expect($result['excluded'])->toBe('snapshot_not_pregame');
    $inputs['home']['elo_evidence']['observed_at'] = '2026-09-04';
    $result = $service->evaluate($inputs, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-03'), 17);
    expect($result['paired'])->toBeFalse()->and($result['margins'])->not->toHaveKey('corrected_elo')
        ->and($result['margins'])->not->toHaveKey('fixed_half_blend');
});
