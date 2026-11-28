<?php

use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflPropCalibrationExperiment;
use App\Services\NFL\NflPropProbabilityExperiment;
use App\Services\NFL\NflPropProspectiveDataset;
use Tests\TestCase;

uses(TestCase::class);

function prospectiveCalibrationRows(): array
{
    $rows = [];
    foreach (range(0, 239) as $i) {
        $test = $i >= 160;
        $rows[] = ['snapshot_id' => $i, 'outcome_key' => 'outcome-'.$i, 'game_group' => 'game-'.intdiv($i, 5),
            'market' => 'player_receptions', 'code_version' => 'one', 'model_version' => 'shadow',
            'week' => $test ? 9 : 8, 'kickoff_at' => $test ? '2026-11-01T18:00:00Z' : '2026-10-25T18:00:00Z',
            'labels_available_at' => $test ? '2026-11-02T18:00:00Z' : '2026-10-26T18:00:00Z',
            'probability' => 0.95, 'push_probability' => 0.0, 'line' => 4.5, 'actual' => $i % 2 ? 5.0 : 3.0,
            'over_price' => -110, 'under_price' => -110];
    }

    return $rows;
}

function prospectiveSnapshot(): PredictionFeatureSnapshot
{
    $s = new PredictionFeatureSnapshot([
        'features' => ['fingerprint_version' => 'round8-v1', 'kickoff_at' => '2026-11-26T18:00:00Z'],
        'outputs' => ['over_probability' => 0.6, 'push_probability' => 0.0],
        'market_context' => ['market' => 'player_receptions', 'line' => 4.5, 'quote_observed_at' => '2026-11-25T18:00:00Z'],
        'model_metadata' => ['player_id' => 1, 'team_id' => 1], 'pregame_safe' => true,
        'generated_at' => '2026-11-25T13:00:00-06:00', 'features_available_at' => '2026-11-25T13:00:00-06:00',
        'game_start_at' => '2026-11-26T12:00:00-06:00',
    ]);
    $s->feature_hash = NflPropProbabilityExperiment::fingerprint($s->features, $s->outputs, $s->market_context);

    return $s;
}

it('validates canonical instants and excludes changed payloads and post-kickoff captures', function () {
    $validator = new NflPropProspectiveDataset;
    $s = prospectiveSnapshot();
    expect($validator->invalidReason($s, '2026-11-26T18:00:00Z'))->toBeNull()
        ->and($validator->invalidReason($s, '2026-11-26T19:00:00Z'))->toBe('canonical_kickoff');
    $s->outputs = ['over_probability' => 0.7, 'push_probability' => 0.0];
    expect($validator->invalidReason($s, '2026-11-26T18:00:00Z'))->toBe('integrity_hash');
    $s = prospectiveSnapshot();
    $s->generated_at = $s->game_start_at;
    expect($validator->invalidReason($s, '2026-11-26T18:00:00Z'))->toBe('pregame_timing');
});

it('rejects future quote information and out of range probabilities even with valid hashes', function () {
    $validator = new NflPropProspectiveDataset;
    $s = prospectiveSnapshot();
    $s->market_context = [...$s->market_context, 'quote_observed_at' => '2026-11-26T17:00:00Z'];
    $s->feature_hash = NflPropProbabilityExperiment::fingerprint($s->features, $s->outputs, $s->market_context);
    expect($validator->invalidReason($s, '2026-11-26T18:00:00Z'))->toBe('quote_timing');
    $s = prospectiveSnapshot();
    $s->outputs = ['over_probability' => 1.01, 'push_probability' => 0.0];
    $s->feature_hash = NflPropProbabilityExperiment::fingerprint($s->features, $s->outputs, $s->market_context);
    expect($validator->invalidReason($s, '2026-11-26T18:00:00Z'))->toBe('invalid_probability');
});

it('fits both monotonic methods and improves an overconfident forecast on an untouched week', function () {
    $service = new NflPropCalibrationExperiment;
    $r = $service->report(['rows' => prospectiveCalibrationRows()]);
    $g = $r['groups'][0];
    expect($g['status'])->toBe('offline_evaluated')->and($g['training_n'])->toBe(160)->and($g['test_n'])->toBe(80)
        ->and($r['production_promotion'])->toBeFalse();
    foreach (['logistic', 'beta'] as $method) {
        expect($g['metrics'][$method]['brier'])->toBeLessThan($g['metrics']['raw']['brier'])
            ->and($g['fits'][$method]['approved'])->toBeFalse()
            ->and($service->predict($g['fits'][$method], 0.2))->toBeLessThanOrEqual($service->predict($g['fits'][$method], 0.8));
    }
});

it('never fits holdout outcomes or labels unavailable at the fitting cutoff', function () {
    $service = new NflPropCalibrationExperiment;
    $rows = prospectiveCalibrationRows();
    $rows[0]['labels_available_at'] = '2026-11-03T18:00:00Z';
    $first = $service->report(['rows' => $rows])['groups'][0];
    foreach ($rows as &$row) {
        if ($row['week'] === 9) {
            $row['actual'] = 50;
        }
    }
    unset($row);
    $second = $service->report(['rows' => $rows])['groups'][0];
    expect($first['training_n'])->toBe(159)->and($first['fits'])->toBe($second['fits']);
});

it('does not inflate evidence with duplicate outcomes or leak a game across weeks', function () {
    $service = new NflPropCalibrationExperiment;
    $rows = prospectiveCalibrationRows();
    $rows[] = $rows[0];
    expect(fn () => $service->report(['rows' => $rows]))->toThrow(InvalidArgumentException::class, 'Duplicate');
    $rows = prospectiveCalibrationRows();
    $rows[160]['game_group'] = $rows[0]['game_group'];
    expect(fn () => $service->report(['rows' => $rows]))->toThrow(InvalidArgumentException::class, 'Game leaked');
});

it('keeps code versions separate and excludes pushes and single-sided quotes from paired metrics', function () {
    $rows = prospectiveCalibrationRows();
    $rows[0]['actual'] = 4.5;
    $rows[160]['under_price'] = null;
    $other = $rows[0];
    $other['code_version'] = 'two';
    $rows[] = $other;
    $result = (new NflPropCalibrationExperiment)->report(['rows' => $rows]);
    expect($result['groups'])->toHaveCount(2)->and($result['groups'][0]['training_n'])->toBe(159)
        ->and($result['groups'][0]['metrics']['raw']['n'])->toBe(79)
        ->and($result['groups'][1]['status'])->toBe('insufficient_training');
});
