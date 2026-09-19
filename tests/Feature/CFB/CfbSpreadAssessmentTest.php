<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbSpreadAssessment;

function cfbSpreadInputs(): array
{
    $team = ['elo' => 1500, 'injuries' => [],
        'metrics' => ['record_season' => 2026, 'wins' => 2, 'losses' => 0, 'fpi' => 0,
            'points_per_game' => 28, 'points_allowed_per_game' => 28],
        'rating_evidence' => ['season' => 2026, 'observed_at' => now()->toIso8601String()],
        'availability' => ['status' => 'last_observed']];

    return ['event' => ['season' => 2026, 'neutral_site' => true], 'home' => $team, 'away' => $team];
}

function cfbSpreadOutput(array $inputs, bool $pointScale = true): mixed
{
    $definition = app(CfbCalculationReleaseDefinition::class);
    $config = $definition->configuration();
    if (! $pointScale) {
        unset($config['spread']['rating_baseline']);
    }
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', $definition->calculatorName(),
        'rules', $definition->semanticVersion(), 'test', 'test', $definition->inputSchemaVersion(), $config);

    return app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, now()->toImmutable()), $release);
}

it('preserves point-scale FPI gaps without adding correlated Elo and power again', function () {
    $inputs = cfbSpreadInputs();
    $inputs['away']['metrics']['fpi'] = 30;
    $inputs['away']['elo'] = 1900;
    $output = cfbSpreadOutput($inputs);
    expect($output->metadata['home_margin'])->toBe(-27.0)
        ->and($output->diagnostics['fpi_home_margin'])->toBe(-30.0)
        ->and($output->diagnostics['power_adjustment'])->toBe(0.0);
    $inputs['away']['elo'] = 1500;
    expect(cfbSpreadOutput($inputs)->metadata['home_margin'])->toBe(-27.0);
    expect(cfbSpreadOutput($inputs, false)->metadata['home_margin'])->toBe(-4.1);
});

it('handles home field and missing paired ratings without inventing an opponent rating', function () {
    $inputs = cfbSpreadInputs();
    $inputs['away']['metrics']['fpi'] = 30;
    $inputs['event']['neutral_site'] = false;
    expect(cfbSpreadOutput($inputs)->metadata['home_margin'])->toBe(-25.0);
    $inputs['home']['metrics']['fpi'] = null;
    expect(cfbSpreadOutput($inputs)->diagnostics['spread_baseline'])->toBe('elo_scoring')
        ->and(cfbSpreadOutput($inputs)->diagnostics['fpi_home_margin'])->toBeNull();
});

it('separates winning big from covering a specific line on either side', function () {
    $service = app(CfbSpreadAssessment::class);
    $inputs = cfbSpreadInputs();
    $result = $service->assess($inputs, -31.4, 39.5);
    expect($result['large_favorite_agreement'])->toBeTrue()
        ->and($result['projected_cover'])->toBe('underdog_cover')
        ->and($result['favorite_cover_edge_points'])->toBe(-8.1)
        ->and($result['cover_probability'])->toBeNull();
    expect($service->assess($inputs, -23, 20.5)['projected_cover'])->toBe('favorite_cover')
        ->and($service->assess($inputs, 23, -20.5)['favorite_cover_edge_points'])->toBe(2.5)
        ->and($service->assess($inputs, 21, -21)['projected_cover'])->toBe('push')
        ->and($service->assess($inputs, 0, 0)['favorite_side'])->toBeNull();
});

it('returns explicit missing evidence instead of a confident cover call', function () {
    $inputs = cfbSpreadInputs();
    $inputs['away']['metrics'] = null;
    unset($inputs['away']['availability'], $inputs['away']['rating_evidence']);
    $result = app(CfbSpreadAssessment::class)->assess($inputs, 35, -20.5);
    expect($result['status'])->toBe('insufficient_evidence')
        ->and($result['risk_flags'])->toContain('away_missing_team_metrics', 'away_missing_opponent_adjusted_rating',
            'away_rating_provenance_unverified', 'away_quarterback_evidence_missing');
});

it('blocks stale or future ratings and carries market gaps through to the decision', function () {
    $inputs = cfbSpreadInputs();
    $inputs['home']['rating_evidence']['observed_at'] = now()->subDays(15)->toIso8601String();
    $inputs['away']['rating_evidence']['observed_at'] = now()->addDay()->toIso8601String();
    $result = app(CfbSpreadAssessment::class)->assess($inputs, 35, -20.5, ['stale_market_quote']);
    expect($result['status'])->toBe('insufficient_evidence')
        ->and($result['risk_flags'])->toContain('home_stale_rating', 'away_stale_rating', 'stale_market_quote');
});
