<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\CFB\Signals\CfbFootballSignalModel;
use App\Services\CFB\Signals\CfbFrozenSignalEvidence;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;

function frozenReleaseCandidate(): array
{
    $configuration = app(CfbCalculationReleaseDefinition::class)->configuration();
    $evidence = ['version' => CfbFootballSignalModel::VERSION,
        'baseline_hash' => CfbFootballSignalEvidence::baselineHash($configuration),
        'catalog_hash' => CfbFootballSignalEvidence::catalogHash($configuration['football_signals']['catalog']),
        'feature_policy' => $configuration['football_signals']['feature_policy'],
        'training_policy' => $configuration['football_signals']['training_policy'],
        'as_of' => now()->subDay()->toIso8601String(), 'latest_source_observed_at' => null,
        'signals' => [], 'joint_models' => ['spread' => ['status' => 'insufficient_evidence', 'coefficients' => []]]];

    return app(CfbFrozenSignalEvidence::class)->freeze($evidence, $configuration, now()->subHour()->toImmutable(), 'stored-source-forecast');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00-05:00'));
});

it('rejects invalid pins before persisting a release even when approval is disabled', function (string $kind) {
    $artifact = frozenReleaseCandidate();
    if ($kind === 'tampered') {
        $artifact['evidence']['joint_models']['spread']['coefficients']['example'] = 20;
    } elseif ($kind === 'future') {
        $artifact['available_at'] = now()->addHour()->toIso8601String();
        $artifact['content_hash'] = app(CanonicalPayloadHasher::class)->hash(array_diff_key($artifact, ['content_hash' => true]));
    } else {
        $artifact = 'invalid';
    }
    config(['cfb.predictions.football_signals.frozen_evidence' => $artifact]);
    expect(fn () => app(CfbCalculationReleaseRegistrar::class)->register('pinned-candidate', approve: false))
        ->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('calculation_releases', 0);
})->with(['tampered', 'future', 'malformed']);

it('preserves exact learned evidence after release storage and later replay without promoting it', function () {
    $artifact = frozenReleaseCandidate();
    config(['cfb.predictions.football_signals.frozen_evidence' => $artifact]);
    $release = app(CfbCalculationReleaseRegistrar::class)->register('pinned-candidate', approve: false)->fresh();
    $before = app(CfbFrozenSignalEvidence::class)->resolve($release->configuration, now()->toImmutable());
    config(['cfb.predictions.football_signals.frozen_evidence' => null]);
    $this->travelTo(now()->addDays(2));
    $after = app(CfbFrozenSignalEvidence::class)->resolve($release->fresh()->configuration, now()->toImmutable());
    expect($after)->toBe($before)
        ->and($release->configuration_hash)->toBe(app(CanonicalPayloadHasher::class)->hash($release->configuration))
        ->and($release->configuration['football_signals']['frozen_evidence'])->toBe($artifact)
        ->and($release->status)->toBe('draft')->and($release->approved_at)->toBeNull()
        ->and($after['joint_models']['spread']['status'])->toBe('insufficient_evidence');
});

it('requires a new release identity when adding a pinned model to an existing release', function () {
    $registrar = app(CfbCalculationReleaseRegistrar::class);
    $original = $registrar->register('existing-release', approve: false);
    config(['cfb.predictions.football_signals.frozen_evidence' => frozenReleaseCandidate()]);
    expect(fn () => $registrar->register('existing-release', approve: false))
        ->toThrow(PredictionLifecycleException::class);
    expect($original->fresh()->configuration)->not->toHaveKey('football_signals.frozen_evidence');
    $this->assertDatabaseCount('calculation_releases', 1);
    expect(CalculationRelease::query()->first()->status)->toBe('draft');
});
