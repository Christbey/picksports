<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbForecastCoordinator;
use App\Services\CFB\Predictions\CfbSignalContributionGrader;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use Carbon\CarbonImmutable;

function coordinatedFixture(): array
{
    $configuration = app(CfbCalculationReleaseDefinition::class)->configuration();
    $configuration['football_signals']['enabled'] = false;
    $at = CarbonImmutable::parse('2026-10-17 09:00:00', 'America/Chicago');
    $metrics = ['wins' => 6, 'losses' => 0, 'record_season' => 2026, 'points_per_game' => 30,
        'points_allowed_per_game' => 20, 'fpi' => 10, 'recent_form_rating' => 0, 'turnover_differential' => 0,
        'rest_travel_fatigue' => 0, 'injury_adjusted_team_rating' => 1500];
    $quote = fn ($line, $id) => ['line' => $line, 'quote_id' => $id, 'captured_at' => $at->subMinute()->toIso8601String(),
        'stored_at' => $at->subMinute()->toIso8601String(), 'provider_observed_at' => $at->subMinutes(2)->toIso8601String()];
    $inputs = ['event' => ['season' => 2026, 'neutral_site' => false],
        'home' => ['elo' => 1500, 'metrics' => $metrics, 'injuries' => []],
        'away' => ['elo' => 1500, 'metrics' => [...$metrics, 'fpi' => 4, 'points_per_game' => 20, 'points_allowed_per_game' => 30], 'injuries' => []],
        'candidate_suite' => ['evidence' => ['status' => 'available', 'result_rating' => ['home_margin' => -2, 'total' => 50],
            'opponent_adjusted_total' => ['total' => 54]], 'market' => [
                'spread' => [...$quote(-3.5, 1), 'opposite_quote' => $quote(3.5, 2)],
                'total' => [...$quote(55.5, 3), 'opposite_quote' => $quote(55.5, 4)],
            ]]];

    return [$configuration, $inputs, $at];
}

function coordinatedCalculate(array $configuration, array $inputs, CarbonImmutable $at): PredictionOutput
{
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.9.0',
        'test', 'test', 'cfb-pregame-v1', $configuration);

    return app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, $at, $at->addHours(2)), $release);
}

it('writes one final bounded forecast while preserving the original team and comparison forecasts', function () {
    [$configuration, $inputs, $at] = coordinatedFixture();
    $output = coordinatedCalculate($configuration, $inputs, $at);
    $spread = collect($output->markets)->first(fn ($m) => $m->marketType === 'spread');
    $total = collect($output->markets)->first(fn ($m) => $m->marketType === 'total');
    expect($spread->projectedLine)->toBe(-7.01)->and($total->projectedLine)->toBe(51.63)
        ->and($output->metadata['forecast_coordination']['original_primary'])->toBe(['home_margin' => 7.4, 'total' => 51.2])
        ->and($output->metadata['comparison_forecasts']['models']['independent']['home_line'])->toBe(-7.4)
        ->and($output->metadata['forecast_coordination']['winner_disagreement'])->toBeTrue()
        ->and($output->metadata['forecast_coordination']['prospectively_validated'])->toBeFalse()
        ->and($output->metadata['forecast_coordination']['effective_weights']['margin_alternative'])->toBe(0.0);
    $without = $configuration;
    unset($without['forecast_coordination']);
    expect(CfbFootballSignalEvidence::baselineHash($configuration))->toBe(CfbFootballSignalEvidence::baselineHash($without));
});

it('keeps historical releases on their original final forecasts', function () {
    [$configuration, $inputs, $at] = coordinatedFixture();
    unset($configuration['forecast_coordination']);
    $output = coordinatedCalculate($configuration, $inputs, $at);
    expect(collect($output->markets)->first(fn ($m) => $m->marketType === 'spread')->projectedLine)->toBe(-7.4)
        ->and($output->metadata)->not->toHaveKey('forecast_coordination');
});

it('discards quotes observed after capture rather than leaking them into a forecast', function () {
    [$configuration, $inputs, $at] = coordinatedFixture();
    $inputs['candidate_suite']['market']['spread']['provider_observed_at'] = $at->addSecond()->toIso8601String();
    $output = coordinatedCalculate($configuration, $inputs, $at);
    expect(collect($output->markets)->first(fn ($m) => $m->marketType === 'spread')->projectedLine)->toBe(-7.4)
        ->and($output->metadata['forecast_coordination']['quote_ids']['spread'])->toBeNull()
        ->and($output->metadata['forecast_coordination']['fallbacks'])->toContain('missing_frozen_market_margin');
});

it('refuses incompatible, tampered, unreviewed or future coordination settings', function (string $change) {
    [$configuration, $inputs, $at] = coordinatedFixture();
    if ($change === 'formula') {
        $configuration['context']['recent_total_weight'] = 0.09;
    } elseif ($change === 'policy') {
        $configuration['forecast_coordination']['policy']['margin_market_weight'] = 0.05;
    } elseif ($change === 'future') {
        $configuration['forecast_coordination']['available_at'] = $at->addMinute()->toIso8601String();
    } else {
        $configuration['forecast_coordination']['historical_accuracy_checks_passed'] = false;
    }
    expect(fn () => coordinatedCalculate($configuration, $inputs, $at))->toThrow(InvalidArgumentException::class);
})->with(['formula', 'policy', 'future', 'review']);

it('allows explicitly labeled counterfactual previews while retaining the original frozen policy', function () {
    [$configuration, $inputs, $at] = coordinatedFixture();
    $counterfactual = $configuration;
    $counterfactual['elo']['home_field_advantage'] = 0;
    $rawConfiguration = $counterfactual;
    unset($rawConfiguration['forecast_coordination']);
    $raw = coordinatedCalculate($rawConfiguration, $inputs, $at);
    $snapshot = new EventInputSnapshotData('cfb-pregame-v1', $inputs, $at, $at->addHours(2));
    $preview = app(CfbForecastCoordinator::class)->preview($raw, $snapshot, $configuration, $counterfactual);
    expect($preview->metadata['forecast_coordination']['status'])->toBe('counterfactual_preview')
        ->and(collect($preview->markets)->first(fn ($m) => $m->marketType === 'spread')->projectedLine)->toBe(-5.21);
    $counterfactual['forecast_coordination']['policy']['margin_market_weight'] = 0;
    expect(fn () => app(CfbForecastCoordinator::class)->preview($raw, $snapshot, $configuration, $counterfactual))
        ->toThrow(InvalidArgumentException::class);
});

it('runs the reproducible evaluation command without modifying input evidence or predictions', function () {
    $rows = [];
    for ($i = 1; $i <= 90; $i++) {
        $day = $i <= 50 ? '2026-09-01' : '2026-09-08';
        $rows[] = ['game_id' => $i, 'prediction_id' => 'p'.$i, 'formula_hash' => 'same-formula',
            'kickoff' => $day.'T18:00:00-05:00', 'captured_at' => $day.'T12:00:00-05:00', 'pregame_safe' => true,
            'primary_margin' => 10, 'primary_total' => 60, 'published_margin' => 10, 'published_total' => 60,
            'result_margin' => -2, 'result_total' => 50, 'opponent_total' => 54, 'market_margin' => 4, 'market_total' => 56,
            'actual_margin' => 4, 'actual_total' => 56];
    }
    $input = tempnam(sys_get_temp_dir(), 'cfb-coordination-input-');
    $output = tempnam(sys_get_temp_dir(), 'cfb-coordination-output-');
    try {
        file_put_contents($input, json_encode($rows, JSON_THROW_ON_ERROR));
        $hash = hash_file('sha256', $input);
        $count = CanonicalPrediction::query()->count();
        $this->artisan('cfb:evaluate-forecast-coordination', [
            '--dataset' => $input, '--validation-from' => '2026-09-08', '--output' => $output,
        ])->assertSuccessful();
        $report = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        expect($report['artifact']['training_games'])->toBe(50)
            ->and($report['historical_validation']['games'])->toBe(40)
            ->and($report['historical_validation']['winner_records']['coordinated']['win'])->toBe(40)
            ->and($report['decision']['forecast_changed'])->toBeFalse()
            ->and(hash_file('sha256', $input))->toBe($hash)
            ->and(CanonicalPrediction::query()->count())->toBe($count);
    } finally {
        unlink($input);
        unlink($output);
    }
});

it('marks infeasible counterfactual diagnostics unavailable without breaking a valid published forecast', function () {
    [$configuration, $inputs, $at] = coordinatedFixture();
    $inputs['home']['metrics'] = [...$inputs['home']['metrics'], 'fpi' => 50, 'points_per_game' => 80, 'points_allowed_per_game' => 10];
    $inputs['away']['metrics'] = [...$inputs['away']['metrics'], 'fpi' => 0, 'points_per_game' => 40, 'points_allowed_per_game' => 10];
    $output = coordinatedCalculate($configuration, $inputs, $at);
    $release = new CalculationRelease(['public_id' => 'test', 'sport' => 'cfb', 'phase' => 'pregame',
        'calculator_name' => 'cfb-pregame-rules', 'release_type' => 'rules', 'semantic_version' => '1.9.0',
        'code_revision' => 'test', 'configuration_hash' => 'test', 'input_schema_version' => 'cfb-pregame-v1', 'configuration' => $configuration]);
    $snapshot = new EventInputSnapshot(['schema_version' => 'cfb-pregame-v1', 'inputs' => $inputs,
        'captured_at' => $at, 'cutoff_at' => $at->addHours(2), 'pregame_safety_status' => 'verified']);
    $run = new CalculationRun(['diagnostics' => $output->diagnostics]);
    $run->setRelation('release', $release)->setRelation('inputSnapshot', $snapshot);
    $prediction = new CanonicalPrediction(['generated_at' => $at]);
    $prediction->setRelation('calculationRun', $run)->setRelation('markets', collect($output->markets)->map(fn ($m) => new PredictionMarket([
        'market_type' => $m->marketType, 'selection' => $m->selection, 'projected_line' => $m->projectedLine,
    ])));
    $graded = app(CfbSignalContributionGrader::class)->evaluate($prediction, $at->addHours(2), null, null);
    $offense = collect($graded['signals'])->first(fn ($r) => $r['signal'] === 'offensive_scoring' && $r['market'] === 'total');
    expect($graded['replay_matched'])->toBeTrue()->and($offense['grade'])->toBe('unavailable')
        ->and($offense['without_signal'])->toBeNull();
});
