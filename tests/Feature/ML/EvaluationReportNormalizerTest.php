<?php

use App\Services\ML\EvaluationReportNormalizer;

it('flattens python walk-forward windows into the canonical dashboard contract', function () {
    $report = [
        'report_type' => 'nfl_tabular_walk_forward_evaluation',
        'walk_forward' => [
            'summary' => [
                'window_count' => 1,
                'avg_brier_delta' => 0.02,
                'avg_log_loss_delta' => 0.03,
            ],
            'windows' => [[
                'test_season' => 2025,
                'champion_classifier' => 'xgboost',
                'classifiers' => [
                    'xgboost' => [
                        'test_calibrated' => [
                            'count' => 272,
                            'brier' => 0.22,
                            'log_loss' => 0.64,
                        ],
                    ],
                ],
                'baselines' => [
                    'classifiers' => [
                        'current_picksports' => [
                            'brier' => 0.24,
                            'log_loss' => 0.67,
                        ],
                    ],
                    'regressors' => [
                        'current_picksports_home_margin' => ['mae' => 11.1],
                        'current_picksports_total_points' => ['mae' => 10.7],
                    ],
                ],
                'regressors' => [
                    'home_margin' => ['mae' => 10.3],
                    'total_points' => ['mae' => 10.9],
                ],
            ]],
        ],
        'promotion_summary' => [
            'offline_challenger_gate_passed' => true,
        ],
    ];

    $normalized = app(EvaluationReportNormalizer::class)->normalize($report);
    $window = $normalized['windows'][0];

    expect($normalized['delta_convention'])->toMatchArray([
        'reported' => 'baseline_minus_challenger',
        'normalized' => 'baseline_minus_challenger',
        'positive_means' => 'challenger_better',
        'source' => 'walk_forward_layout',
    ])->and($window)->toMatchArray([
        'evaluation_season' => 2025,
        'games' => 272,
        'baseline_brier' => 0.24,
        'challenger_brier' => 0.22,
        'brier_delta' => 0.02,
        'baseline_log_loss' => 0.67,
        'challenger_log_loss' => 0.64,
        'log_loss_delta' => 0.03,
        'baseline_spread_mae' => 11.1,
        'challenger_spread_mae' => 10.3,
        'spread_mae_delta' => 0.8,
        'baseline_total_mae' => 10.7,
        'challenger_total_mae' => 10.9,
        'total_mae_delta' => -0.2,
        'delta_convention' => 'baseline_minus_challenger',
    ])->and($window['raw']['champion_classifier'])->toBe('xgboost')
        ->and($normalized['promotion_summary']['offline_challenger_gate_passed'])->toBeTrue();
});

it('normalizes legacy Laravel deltas to positive-is-better dashboard values', function () {
    $normalized = app(EvaluationReportNormalizer::class)->normalize([
        'summary' => [
            'window_count' => 1,
            'avg_brier_delta' => -0.01,
            'avg_log_loss_delta' => -0.03,
        ],
        'windows' => [[
            'evaluation_season' => 2024,
            'games' => 271,
            'baseline_brier' => 0.24,
            'challenger_brier' => 0.23,
            'brier_delta' => -0.01,
            'baseline_log_loss' => 0.68,
            'challenger_log_loss' => 0.65,
            'log_loss_delta' => -0.03,
            'spread_mae_delta' => -0.4,
        ]],
    ]);

    expect($normalized['delta_convention']['reported'])->toBe('challenger_minus_baseline')
        ->and($normalized['summary']['avg_brier_delta'])->toBe(0.01)
        ->and($normalized['summary']['avg_log_loss_delta'])->toBe(0.03)
        ->and($normalized['summary']['delta_convention'])->toBe('baseline_minus_challenger')
        ->and($normalized['raw_summary']['avg_brier_delta'])->toBe(-0.01)
        ->and($normalized['windows'][0]['brier_delta'])->toBe(0.01)
        ->and($normalized['windows'][0]['log_loss_delta'])->toBe(0.03)
        ->and($normalized['windows'][0]['spread_mae_delta'])->toBe(0.4);
});
