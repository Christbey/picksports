<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\CFB\Signals\CfbFootballSignalModel;
use App\Services\CFB\Signals\CfbFrozenSignalEvidence;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function frozenSignalFixture(): array
{
    $config = ['elo' => ['home_field_advantage' => 55], 'football_signals' => [
        'enabled' => true, 'catalog' => [], 'maximum_spread_adjustment' => 2.0,
        'feature_policy' => 'observed_only', 'training_policy' => 'same_release_frozen_predictions']];
    $evidence = ['version' => CfbFootballSignalModel::VERSION,
        'baseline_hash' => CfbFootballSignalEvidence::baselineHash($config),
        'catalog_hash' => CfbFootballSignalEvidence::catalogHash([]),
        'feature_policy' => 'observed_only', 'training_policy' => 'same_release_frozen_predictions',
        'as_of' => '2026-10-20T10:00:00-05:00', 'latest_source_observed_at' => '2026-10-19T10:00:00-05:00',
        'signals' => [], 'joint_models' => ['spread' => ['status' => 'insufficient_evidence', 'coefficients' => ['example' => 0.4]]]];

    return [$config, $evidence, CarbonImmutable::parse('2026-10-21T10:00:00-05:00')];
}

it('pins exact learned evidence across later capture times without upgrading its validation status', function () {
    [$config, $evidence, $available] = frozenSignalFixture();
    $service = new CfbFrozenSignalEvidence;
    $artifact = $service->freeze($evidence, $config, $available, 'source-forecast');
    $config['football_signals']['frozen_evidence'] = $artifact;
    $first = $service->resolve($config, $available->addHour());
    $later = $service->resolve($config, $available->addDays(3));
    expect($first)->toBe($later)->and($first['joint_models'])->toBe($evidence['joint_models'])
        ->and($first['as_of'])->toBe($evidence['as_of'])
        ->and($first['joint_models']['spread']['status'])->toBe('insufficient_evidence')
        ->and($artifact['promotion'])->toBe('candidate_only_no_automatic_release_activation');
});

it('retains legacy behavior only when no explicit artifact was requested', function () {
    [$config, , $at] = frozenSignalFixture();
    expect((new CfbFrozenSignalEvidence)->resolve($config, $at))->toBeNull();
    $config['football_signals']['frozen_evidence'] = 'invalid';
    expect(fn () => (new CfbFrozenSignalEvidence)->resolve($config, $at))->toThrow(InvalidArgumentException::class);
});

it('identifies the fixed artifact in new summaries without changing legacy summary shape', function () {
    [$config, $evidence, $at] = frozenSignalFixture();
    $freezer = new CfbFrozenSignalEvidence;
    $artifact = $freezer->freeze($evidence, $config, $at, 'source');
    $inputs = ['event' => ['starts_at' => $at->addDay()->toIso8601String()], 'football_signal_evidence' => $evidence];
    $model = app(CfbFootballSignalModel::class);
    expect($model->evaluate($inputs, $config['football_signals'], $config, $at))->not->toHaveKey('frozen_artifact');
    $config['football_signals']['frozen_evidence'] = $artifact;
    $inputs['football_signal_evidence'] = $freezer->resolve($config, $at);
    expect($model->evaluate($inputs, $config['football_signals'], $config, $at)['frozen_artifact']['content_hash'])
        ->toBe($artifact['content_hash']);
});

it('rejects altered coefficients, incompatible settings, and future availability', function (string $change) {
    [$config, $evidence, $at] = frozenSignalFixture();
    $service = new CfbFrozenSignalEvidence;
    $config['football_signals']['frozen_evidence'] = $service->freeze($evidence, $config, $at, 'source');
    if ($change === 'coefficient') {
        $config['football_signals']['frozen_evidence']['evidence']['joint_models']['spread']['coefficients']['example'] = 9;
    } elseif ($change === 'baseline') {
        $config['elo']['home_field_advantage'] = 99;
    } elseif ($change === 'cap') {
        $config['football_signals']['maximum_spread_adjustment'] = 3;
    } elseif ($change === 'catalog') {
        $config['football_signals']['catalog'] = ['different' => []];
    } elseif ($change === 'training_policy') {
        $config['football_signals']['training_policy'] = 'different';
    } else {
        $at = $at->subSecond();
    }
    expect(fn () => $service->resolve($config, $at))->toThrow(InvalidArgumentException::class);
})->with(['coefficient', 'baseline', 'cap', 'catalog', 'training_policy', 'future']);

it('rejects training or source observations newer than the artifact availability', function (string $field) {
    [$config, $evidence, $at] = frozenSignalFixture();
    $evidence[$field] = $at->addHour()->toIso8601String();
    expect(fn () => (new CfbFrozenSignalEvidence)->freeze($evidence, $config, $at, 'source'))
        ->toThrow(InvalidArgumentException::class);
})->with(['as_of', 'latest_source_observed_at']);

it('requires explicit source and destination and refuses to overwrite a frozen file', function () {
    $this->artisan('cfb:freeze-signal-evidence')->assertFailed();
    $path = tempnam(sys_get_temp_dir(), 'cfb-frozen-candidate-');
    file_put_contents($path, 'preserve-this');
    try {
        $this->artisan('cfb:freeze-signal-evidence', ['--prediction' => 'source', '--output' => $path])->assertFailed();
        expect(file_get_contents($path))->toBe('preserve-this');
    } finally {
        unlink($path);
    }
});

it('rejects a snapshot whose weights or provenance differ from the pinned release before calculating', function (string $change) {
    [$config, $evidence, $at] = frozenSignalFixture();
    $freezer = new CfbFrozenSignalEvidence;
    $config['football_signals']['frozen_evidence'] = $freezer->freeze($evidence, $config, $at, 'source');
    $inputs = ['football_signal_evidence' => $freezer->resolve($config, $at)];
    if ($change === 'coefficient') {
        $inputs['football_signal_evidence']['joint_models']['spread']['coefficients']['example'] = 100;
    } elseif ($change === 'marker') {
        $inputs['football_signal_evidence']['frozen_artifact']['content_hash'] = 'different';
    } elseif ($change === 'missing_marker') {
        unset($inputs['football_signal_evidence']['frozen_artifact']);
    } else {
        unset($inputs['football_signal_evidence']);
    }
    $snapshot = new EventInputSnapshotData('cfb-pregame-v1', $inputs, $at);
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', 'pinned-test', 'test', 'test', 'cfb-pregame-v1', $config);
    expect(fn () => app(CfbCalculator::class)->calculate($snapshot, $release))
        ->toThrow(InvalidArgumentException::class, 'CFB snapshot signal evidence does not match the pinned release artifact.');
})->with(['coefficient', 'marker', 'missing_marker', 'missing_evidence']);

it('accepts unchanged pinned evidence after serialization but never replaces mismatched snapshot inputs', function () {
    [$config, $evidence, $at] = frozenSignalFixture();
    $freezer = new CfbFrozenSignalEvidence;
    $config['football_signals']['frozen_evidence'] = $freezer->freeze($evidence, $config, $at, 'source');
    $inputs = json_decode(json_encode(['football_signal_evidence' => $freezer->resolve($config, $at)], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $before = $inputs;
    $freezer->assertSnapshotMatches($inputs, $config, $at);
    expect($inputs)->toBe($before);
    unset($config['football_signals']['frozen_evidence']);
    $freezer->assertSnapshotMatches([], $config, $at);
});
