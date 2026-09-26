<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Support\NflBetRuleEngine;
use App\Support\NflReasonCodeCatalog;
use App\Support\NflValidatedSignalCombos;

it('describes sack and rushing inputs without inventing blitz coverage pace or explosives', function () {
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $metadata = [
        'line_matchup' => [
            'applied' => true,
            'home_run_edge' => 0.8,
            'away_run_edge' => -0.4,
            'home_pressure_edge' => 0.08,
            'away_pressure_edge' => 0.18,
            'signal_spread' => 3.0,
            'total_signal' => -2.0,
            'home' => [
                'def_sack_rate' => 0.09,
                'def_rush_yards_allowed_per_attempt' => 3.6,
                'off_pass_attempts' => 70,
                'off_rush_attempts' => 30,
            ],
            'away' => [
                'def_sack_rate' => 0.04,
                'def_rush_yards_allowed_per_attempt' => 5.0,
            ],
        ],
    ];
    $property = new ReflectionProperty($action, 'lastModelMetadata');
    $property->setValue($action, $metadata);
    $codes = [];
    (new ReflectionMethod($action, 'appendLineAndStyleReasonCodes'))->invokeArgs($action, [&$codes, 'home']);

    expect($codes)->toContain(
        'high_combined_sack_rate_proxy',
        'high_defensive_sack_rate_context',
        'low_defensive_sack_rate_context',
        'model_side_sack_matchup_proxy',
        'model_side_rushing_efficiency_matchup_proxy',
        'trench_total_decrease_proxy',
        'high_pass_attempt_share_context',
    )->not->toContain(
        'weak_ol_vs_blitz_heavy_defense',
        'elite_defense_edge',
        'explosive_play_prevention_edge',
        'poor_secondary_risk',
        'ol_pass_protection_edge',
        'dl_pressure_edge',
        'pressure_mismatch_against_qb',
        'run_heavy_clock_control',
        'slow_pace_under_signal',
        'bend_dont_break_defense',
    )->and($property->getValue($action))->toBe($metadata);

    foreach (app(NflReasonCodeCatalog::class)->metadataForCodes($codes) as $entry) {
        expect($entry['is_diagnostic'])->toBeTrue()
            ->and($entry['is_actionable'])->toBeFalse();
    }

    $tokens = [...$codes, 'qb_form_signal', 'recent_matchup_record_context', 'calibration_reduced_confidence'];
    expect(app(NflValidatedSignalCombos::class)->match($tokens))->toBe([]);
    $rules = app(NflBetRuleEngine::class);
    expect($rules->evaluate($tokens, [], 70, null, -4.0))
        ->toBe($rules->evaluate([], [], 70, null, -4.0));
});

it('does not infer fast pace or explosive plays from a positive trench total', function () {
    $action = app(GeneratePredictionFromHistoricalElo::class);
    (new ReflectionProperty($action, 'lastModelMetadata'))->setValue($action, [
        'line_matchup' => ['applied' => true, 'total_signal' => 2.0],
    ]);
    $codes = [];
    (new ReflectionMethod($action, 'appendLineAndStyleReasonCodes'))->invokeArgs($action, [&$codes, 'away']);

    expect($codes)->toBe(['trench_total_increase_proxy']);
});

it('does not emit trench diagnostics when the input layer was not applied', function () {
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $codes = [];
    (new ReflectionMethod($action, 'appendLineAndStyleReasonCodes'))->invokeArgs($action, [&$codes, 'home']);

    expect($codes)->toBe([]);
});
