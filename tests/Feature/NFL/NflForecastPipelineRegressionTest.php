<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Services\NFL\NflProSignalLayer;
use App\Support\NflBetRuleEngine;
use App\Support\NflReasonCodeCatalog;
use App\Support\NflValidatedSignalCombos;

it('preserves all fifteen ordered forecast stages and each intermediate argument', function () {
    $game = new Game;
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $stages = [
        ['applyTrueEpaBlend', [1.0, 0.51, 41.0], [2.0, 0.52, 42.0]],
        ['applyPreseasonSignalBlend', [2.0, 0.52], [3.0, 0.53]],
        ['applyRollingEfficiencyBlend', [3.0, 0.53, 42.0], [4.0, 0.54, 43.0]],
        ['applyOpponentAdjustedEfficiencyBlend', [4.0, 0.54, 43.0], [5.0, 0.55, 44.0]],
        ['applyTotalEnvironmentBlend', [44.0], 45.0],
        ['applyQbFormBlend', [5.0, 0.55], [6.0, 0.56]],
        ['applyLineMatchupBlend', [6.0, 0.56, 45.0], [7.0, 0.57, 46.0]],
        ['applyContextualFactorsBlend', [7.0, 0.57, 46.0], [8.0, 0.58, 47.0]],
        ['applyActualWeatherBlend', [8.0, 0.58, 47.0], [9.0, 0.59, 48.0]],
        ['applyDepthChartInjuryAdjustments', [9.0, 0.59, 48.0], [10.0, 0.60, 49.0]],
        ['applyAdaptivePointCalibration', [10.0, 0.60, 49.0], [11.0, 0.61, 50.0]],
        ['applyPlayerPositionGradeContext', [], null],
        ['applyMarketBlend', [11.0, 0.61, 50.0], [12.0, 0.62, 51.0]],
        ['applyAdaptiveWinProbabilityCalibration', [0.62], 0.63],
        ['applyAutomatedCalibrationTweaks', [12.0, 0.63, 51.0], [13.0, 0.64, 52.0]],
    ];
    foreach ($stages as [$method, $arguments, $result]) {
        $expectation = $action->shouldReceive($method)->once()->with($game, ...$arguments)->ordered();
        if ($method === 'applyPlayerPositionGradeContext') {
            $expectation->andReturnUsing(function () use ($action): void {
                (new ReflectionProperty($action, 'lastModelMetadata'))->setValue($action, ['player_position_grades' => ['shadow_only' => true]]);
            });
        } else {
            $expectation->andReturn($result);
        }
    }

    $result = (new ReflectionMethod($action, 'calculateForecast'))->invoke($action, $game, 1.0, 0.51, 41.0);

    expect($result)->toBe([13.0, 0.64, 52.0])
        ->and((new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action))
        ->toBe(['player_position_grades' => ['shadow_only' => true]]);
});

it('preserves the clamped logistic spread conversion for configured coefficients', function (float $spread, float $coefficient) {
    config()->set('nfl.predictions.spread_to_probability_coefficient', $coefficient);
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $expected = max(0.01, min(0.99, 1 / (1 + exp(-$spread / $coefficient))));

    $probability = (new ReflectionMethod($action, 'winProbabilityFromSpread'))->invoke($action, $spread);

    expect($probability)->toBe($expected);
})->with([-1000.0, -21.0, -7.0, -0.5, 0.0, 0.5, 7.0, 21.0, 1000.0])->with([3.5, 7.0, 14.0]);

it('builds expensive reason evidence once using the final key-number-adjusted trust score', function () {
    config()->set([
        'nfl.predictions.analysis_layer.enabled' => true,
        'nfl.predictions.automated_calibration_tweaks.enabled' => true,
        'nfl.predictions.allow_unvalidated_trust_boosts' => true,
        'nfl.predictions.automated_calibration_tweaks.big_spread.enabled' => false,
        'nfl.predictions.automated_calibration_tweaks.tight_spread.enabled' => false,
        'nfl.predictions.automated_calibration_tweaks.key_number.edge_10_trust_boost' => 6.0,
        'nfl.betting.key_numbers' => [3, 7, 10],
    ]);
    $game = new Game;
    $codes = ['strong_model_signal', 'key_number_edge_10'];
    $proSignal = Mockery::mock(NflProSignalLayer::class);
    $proSignal->shouldReceive('build')->once()->withArgs(function ($actualGame, $metadata, $analysis, $spread, $total, $probability) use ($game, $codes): bool {
        return $actualGame === $game && $analysis['trust_score'] === 86.0
            && $analysis['reason_codes'] === $codes && $spread === 14.0 && $total === 46.0 && $probability === 0.7;
    })->andReturn(['tier' => 'official_candidate', 'market_scores' => ['spread' => ['tier' => 'official_candidate']]]);
    $rules = Mockery::mock(NflBetRuleEngine::class);
    $rules->shouldReceive('evaluate')->once()->with($codes, [], 86.0, 4.0, 2.0)->andReturn(['action' => 'none']);
    app()->instance(NflBetRuleEngine::class, $rules);
    $combos = Mockery::mock(NflValidatedSignalCombos::class);
    $combos->shouldReceive('match')->once()->with($codes)->andReturn([]);
    app()->instance(NflValidatedSignalCombos::class, $combos);
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    (new ReflectionProperty($action, 'proSignalLayer'))->setValue($action, $proSignal);
    (new ReflectionProperty($action, 'reasonCodeCatalog'))->setValue($action, app(NflReasonCodeCatalog::class));
    $action->shouldReceive('extractMarketSpreadAndTotalFromGame')->once()->with($game)->andReturn([10.0, 44.0]);
    $action->shouldReceive('analysisRiskFlags')->once()->with($game, 0.7, 4.0, 2.0)->andReturn([]);
    $action->shouldReceive('analysisTrustScore')->once()->with(0.7, [], 4.0, 2.0)->andReturn(80.0);
    $action->shouldReceive('isEarlySeasonCalibrationWeek')->once()->with($game)->andReturn(false);
    $action->shouldReceive('analysisReasonCodes')->once()->with($game, 0.7, 86.0, 4.0, 2.0)->andReturn($codes);

    (new ReflectionMethod($action, 'applyAnalysisLayer'))->invoke($action, $game, 14.0, 46.0, 0.7);
    $metadata = (new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action);

    expect($metadata['analysis_layer']['trust_score'])->toBe(86.0)
        ->and($metadata['analysis_layer']['reason_codes'])->toBe($codes)
        ->and(data_get($metadata, 'automated_calibration_tweaks.analysis_trust_adjustments.adjustments'))
        ->toBe([['name' => 'key_number_10_trust_boost', 'adjustment' => 6.0]]);
});

it('preserves key-number proximity and crossing semantics on either side', function (?float $market, ?float $edge, array $expected) {
    config()->set('nfl.betting.key_numbers', [3, 7, 10]);
    $action = app(GeneratePredictionFromHistoricalElo::class);

    expect((new ReflectionMethod($action, 'marketKeyNumberReasonCodes'))->invoke($action, $market, $edge))->toBe($expected);
})->with([
    'no quote' => [null, 4.0, []],
    'home half-point boundary' => [6.5, null, ['key_number_edge_7']],
    'away half-point boundary' => [-10.5, null, ['key_number_edge_10']],
    'outside proximity' => [6.499, null, []],
    'cross toward favorite seven' => [6.5, 1.0, ['key_number_edge_7', 'spread_crosses_key_number']],
    'cross toward underdog seven' => [-6.5, -1.0, ['key_number_edge_7', 'spread_crosses_key_number']],
    'land exactly seven' => [8.0, -1.0, ['spread_crosses_key_number']],
    'start exactly seven is not crossing' => [7.0, 1.0, ['key_number_edge_7']],
    'cross two keys preserves raw duplicates' => [2.0, 6.0, ['spread_crosses_key_number', 'spread_crosses_key_number']],
]);
