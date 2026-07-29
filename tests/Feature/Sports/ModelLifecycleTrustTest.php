<?php

use App\Models\BetDecision;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\ModelArtifact;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('promotes a challenger only after it passes multiple chronological windows', function () {
    config([
        'ml.promotion.live_shadow.minimum_observations' => 0,
        'ml.promotion.live_shadow.minimum_settled_decisions' => 0,
    ]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'training',
        modelVersion: 'nba-calibration-v1',
        featureVersion: 'trusted-snapshot-v1',
        blendVersion: 'challenger-shadow-v1',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nba',
        'market_type' => 'win_probability',
        'model_type' => 'platt_calibration',
        'model_version' => 'nba-calibration-v1',
        'feature_version' => 'trusted-snapshot-v1',
        'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => storage_path('app/ml/models/test-promotion.json'),
        'artifact_hash' => str_repeat('b', 64),
        'status' => 'challenger',
    ]);
    $reportPath = storage_path('app/ml/reports/test-promotion.json');
    @mkdir(dirname($reportPath), 0777, true);
    file_put_contents($reportPath, json_encode([
        'summary' => [
            'window_count' => 4,
            'challenger_better_window_count' => 3,
            'avg_brier_delta' => -0.012,
            'avg_log_loss_delta' => -0.018,
        ],
        'windows' => [
            ['evaluation_season' => 2022, 'games' => 100, 'brier_delta' => -0.01, 'log_loss_delta' => -0.01],
            ['evaluation_season' => 2023, 'games' => 100, 'brier_delta' => -0.02, 'log_loss_delta' => -0.03],
            ['evaluation_season' => 2024, 'games' => 100, 'brier_delta' => 0.005, 'log_loss_delta' => 0.01],
            ['evaluation_season' => 2025, 'games' => 100, 'brier_delta' => -0.023, 'log_loss_delta' => -0.042],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
    ]);

    expect($artifact->refresh()->status)->toBe('promotion_eligible')
        ->and($artifact->promoted_at)->toBeNull();

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
        '--promote' => true,
    ]);

    expect($artifact->refresh()->status)->toBe('promoted')
        ->and($artifact->promoted_at)->not->toBeNull()
        ->and($artifact->trainingRun->config_hash)->toHaveLength(64)
        ->and($artifact->artifact_hash)->toBe(str_repeat('b', 64));
});

it('evaluates a multi-market NFL artifact against chronological probability and point baselines', function () {
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-tabular-v1',
        featureVersion: 'nfl-pregame-ml-v3',
        blendVersion: 'nfl-tabular-v1',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nfl',
        'market_type' => 'multi_market',
        'model_type' => 'nfl_tabular_bundle',
        'model_version' => 'nfl-tabular-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'dataset_hash' => str_repeat('7', 64),
        'artifact_path' => storage_path('app/ml/models/test-nfl-tabular.zip'),
        'artifact_hash' => str_repeat('8', 64),
        'status' => 'challenger',
    ]);
    $reportPath = storage_path('app/ml/reports/test-nfl-tabular-promotion.json');
    @mkdir(dirname($reportPath), 0777, true);
    file_put_contents($reportPath, json_encode([
        'summary' => [
            'window_count' => 4,
            'challenger_better_window_count' => 3,
            'avg_brier_delta' => -0.01,
            'avg_log_loss_delta' => -0.02,
            'avg_spread_mae_delta' => -0.4,
            'avg_total_mae_delta' => -0.3,
        ],
        'windows' => [
            [
                'evaluation_season' => 2022,
                'brier_delta' => -0.01,
                'log_loss_delta' => -0.02,
                'spread_mae_delta' => -0.4,
                'total_mae_delta' => -0.3,
            ],
            [
                'evaluation_season' => 2023,
                'brier_delta' => -0.02,
                'log_loss_delta' => -0.03,
                'spread_mae_delta' => -0.5,
                'total_mae_delta' => -0.2,
            ],
            [
                'evaluation_season' => 2024,
                'brier_delta' => 0.005,
                'log_loss_delta' => 0.01,
                'spread_mae_delta' => 0.1,
                'total_mae_delta' => 0.1,
            ],
            [
                'evaluation_season' => 2025,
                'brier_delta' => -0.015,
                'log_loss_delta' => -0.04,
                'spread_mae_delta' => -0.8,
                'total_mae_delta' => -0.8,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
    ]);

    expect($artifact->refresh()->status)->toBe('promotion_eligible')
        ->and(data_get($artifact->promotion_decision, 'markets.spread.eligible'))->toBeTrue()
        ->and(data_get($artifact->promotion_decision, 'markets.total.eligible'))->toBeTrue()
        ->and(data_get($artifact->promotion_decision, 'delta_convention.reported'))
        ->toBe('challenger_minus_baseline');
});

it('promotes passing NFL markets without allowing a weak total model to block or leak', function () {
    config([
        'ml.promotion.live_shadow.minimum_observations' => 0,
        'ml.promotion.live_shadow.minimum_settled_decisions' => 0,
    ]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-tabular-market-gates-v1',
        featureVersion: 'nfl-pregame-ml-v3',
        blendVersion: 'nfl-tabular-v1',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nfl',
        'market_type' => 'multi_market',
        'model_type' => 'nfl_tabular_bundle',
        'model_version' => 'nfl-tabular-market-gates-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'dataset_hash' => str_repeat('9', 64),
        'artifact_path' => storage_path('app/ml/models/test-nfl-market-gates.zip'),
        'artifact_hash' => str_repeat('a', 64),
        'status' => 'challenger',
    ]);
    $pythonWindow = function (
        int $season,
        float $baselineBrier,
        float $challengerBrier,
        float $baselineLogLoss,
        float $challengerLogLoss,
        float $baselineSpreadMae,
        float $challengerSpreadMae,
        float $baselineTotalMae,
        float $challengerTotalMae,
    ): array {
        return [
            'test_season' => $season,
            'champion_classifier' => 'xgboost',
            'classifiers' => [
                'xgboost' => [
                    'test_calibrated' => [
                        'count' => 272,
                        'brier' => $challengerBrier,
                        'log_loss' => $challengerLogLoss,
                    ],
                ],
            ],
            'baselines' => [
                'classifiers' => [
                    'current_picksports' => [
                        'brier' => $baselineBrier,
                        'log_loss' => $baselineLogLoss,
                    ],
                ],
                'regressors' => [
                    'current_picksports_home_margin' => ['mae' => $baselineSpreadMae],
                    'current_picksports_total_points' => ['mae' => $baselineTotalMae],
                ],
            ],
            'regressors' => [
                'home_margin' => ['mae' => $challengerSpreadMae],
                'total_points' => ['mae' => $challengerTotalMae],
            ],
        ];
    };
    $reportPath = storage_path('app/ml/reports/test-nfl-market-specific-promotion.json');
    @mkdir(dirname($reportPath), 0777, true);
    file_put_contents($reportPath, json_encode([
        'report_type' => 'nfl_tabular_walk_forward_evaluation',
        'walk_forward' => [
            'summary' => [
                'window_count' => 3,
                'challenger_better_window_count' => 3,
                'avg_brier_delta' => 0.01,
                'avg_log_loss_delta' => 0.02,
            ],
            'windows' => [
                $pythonWindow(2023, 0.24, 0.23, 0.68, 0.66, 11.0, 10.5, 10.0, 10.2),
                $pythonWindow(2024, 0.23, 0.22, 0.66, 0.64, 10.8, 10.4, 10.2, 10.5),
                $pythonWindow(2025, 0.24, 0.23, 0.67, 0.65, 10.5, 10.6, 10.4, 10.6),
            ],
        ],
        'promotion_summary' => [
            'offline_challenger_gate_passed' => true,
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
        '--promote' => true,
    ]);

    $artifact->refresh();
    expect($artifact->status)->toBe('promoted')
        ->and($artifact->promotedMarkets())->toBe(['win_probability', 'spread'])
        ->and($artifact->isPromotedForMarket('moneyline'))->toBeTrue()
        ->and($artifact->isPromotedForMarket('spread'))->toBeTrue()
        ->and($artifact->isPromotedForMarket('total'))->toBeFalse()
        ->and(data_get($artifact->promotion_decision, 'markets.total.eligible'))->toBeFalse()
        ->and(data_get($artifact->promotion_decision, 'delta_convention.source'))->toBe('walk_forward_layout');
});

it('blocks promotion when one chronological window exceeds the regression ceiling', function () {
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-tabular-worst-window-v1',
        featureVersion: 'nfl-pregame-ml-v3',
        blendVersion: 'nfl-tabular-v1',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nfl',
        'market_type' => 'win_probability',
        'model_type' => 'nfl_tabular_bundle',
        'model_version' => 'nfl-tabular-worst-window-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'dataset_hash' => str_repeat('b', 64),
        'artifact_path' => storage_path('app/ml/models/test-nfl-worst-window.zip'),
        'artifact_hash' => str_repeat('c', 64),
        'status' => 'challenger',
    ]);
    $window = fn (int $season, float $baselineBrier, float $challengerBrier, float $baselineLogLoss, float $challengerLogLoss): array => [
        'test_season' => $season,
        'champion_classifier' => 'xgboost',
        'classifiers' => [
            'xgboost' => [
                'test_calibrated' => [
                    'count' => 272,
                    'brier' => $challengerBrier,
                    'log_loss' => $challengerLogLoss,
                ],
            ],
        ],
        'baselines' => [
            'classifiers' => [
                'current_picksports' => [
                    'brier' => $baselineBrier,
                    'log_loss' => $baselineLogLoss,
                ],
            ],
        ],
    ];
    $reportPath = storage_path('app/ml/reports/test-nfl-worst-window.json');
    @mkdir(dirname($reportPath), 0777, true);
    file_put_contents($reportPath, json_encode([
        'report_type' => 'nfl_tabular_walk_forward_evaluation',
        'walk_forward' => [
            'summary' => [
                'window_count' => 3,
                'challenger_better_window_count' => 2,
                'avg_brier_delta' => 0.023333,
                'avg_log_loss_delta' => 0.03,
            ],
            'windows' => [
                $window(2023, 0.25, 0.20, 0.70, 0.60),
                $window(2024, 0.25, 0.20, 0.70, 0.60),
                $window(2025, 0.22, 0.25, 0.60, 0.71),
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
        '--promote' => true,
    ]);

    $artifact->refresh();
    expect($artifact->status)->toBe('challenger')
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.checks.positive_average_primary_metric'))->toBeTrue()
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.checks.better_window_rate'))->toBeTrue()
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.checks.worst_primary_window_regression'))->toBeFalse()
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.checks.worst_secondary_window_regression'))->toBeFalse();
    expect(abs((float) data_get(
        $artifact->promotion_decision,
        'markets.win_probability.worst_primary_window_regression',
    ) - 0.03))->toBeLessThan(0.000000001)
        ->and(abs((float) data_get(
            $artifact->promotion_decision,
            'markets.win_probability.worst_secondary_window_regression',
        ) - 0.11))->toBeLessThan(0.000000001);
});

it('keeps offline eligibility but blocks promotion when live shadow evidence is below the configured minimum', function () {
    config([
        'ml.promotion.live_shadow.minimum_observations' => 2,
        'ml.promotion.live_shadow.minimum_settled_decisions' => 0,
    ]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'training',
        modelVersion: 'nba-live-evidence-v1',
        featureVersion: 'trusted-snapshot-v1',
        blendVersion: 'challenger-shadow-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nba',
        'market_type' => 'win_probability',
        'model_type' => 'platt_calibration',
        'model_version' => 'nba-live-evidence-v1',
        'feature_version' => 'trusted-snapshot-v1',
        'dataset_hash' => str_repeat('d', 64),
        'artifact_path' => storage_path('app/ml/models/test-live-evidence.json'),
        'artifact_hash' => str_repeat('e', 64),
        'status' => 'challenger',
    ]);
    $reportPath = storage_path('app/ml/reports/test-live-evidence.json');
    @mkdir(dirname($reportPath), 0777, true);
    file_put_contents($reportPath, json_encode([
        'summary' => [
            'window_count' => 3,
            'challenger_better_window_count' => 3,
            'avg_brier_delta' => -0.01,
            'avg_log_loss_delta' => -0.02,
        ],
        'windows' => [
            ['evaluation_season' => 2023, 'brier_delta' => -0.01, 'log_loss_delta' => -0.02],
            ['evaluation_season' => 2024, 'brier_delta' => -0.01, 'log_loss_delta' => -0.02],
            ['evaluation_season' => 2025, 'brier_delta' => -0.01, 'log_loss_delta' => -0.02],
        ],
    ], JSON_THROW_ON_ERROR));
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = now()->addDay();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED',
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: now(),
    );
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 9001,
        'game_id' => $game->id,
        'model_run_id' => $inferenceRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'feature_hash' => str_repeat('f', 64),
        'generated_at' => now(),
        'game_start_at' => $gameStart,
        'features_available_at' => now(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 9001,
        'market_type' => 'win_probability',
        'baseline_output' => 0.55,
        'challenger_output' => 0.60,
        'output_delta' => 0.05,
        'status' => 'shadow',
        'generated_at' => now(),
    ]);

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
        '--promote' => true,
    ]);

    $artifact->refresh();
    expect($artifact->status)->toBe('promotion_eligible')
        ->and($artifact->promoted_at)->toBeNull()
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.eligible'))->toBeTrue()
        ->and(data_get($artifact->promotion_decision, 'markets.win_probability.promotion_ready'))->toBeFalse()
        ->and(data_get($artifact->promotion_decision, 'live_shadow_evidence.markets.win_probability.live_pregame_safe_observations'))->toBe(1)
        ->and(data_get($artifact->promotion_decision, 'live_shadow_evidence.markets.win_probability.minimum_live_pregame_safe_observations'))->toBe(2)
        ->and(data_get($artifact->promotion_decision, 'live_shadow_evidence.markets.win_probability.checks.minimum_live_observations'))->toBeFalse()
        ->and(Artisan::output())->toContain('PROMOTION BLOCKED: LIVE SHADOW EVIDENCE');
});

it('records only decision-time eligible shadow bets and closes their feedback loop', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = Carbon::parse('2026-01-10 19:00:00');
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 100,
    ]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'training',
        modelVersion: 'nba-calibration-v1',
        featureVersion: 'trusted-snapshot-v1',
        blendVersion: 'challenger-shadow-v1',
        status: 'completed',
        completedAt: Carbon::parse('2026-01-09 12:00:00'),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nba',
        'market_type' => 'win_probability',
        'model_type' => 'platt_calibration',
        'model_version' => 'nba-calibration-v1',
        'feature_version' => 'trusted-snapshot-v1',
        'dataset_hash' => str_repeat('c', 64),
        'artifact_path' => storage_path('app/ml/models/test-shadow.json'),
        'artifact_hash' => str_repeat('d', 64),
        'status' => 'promoted',
        'promoted_at' => Carbon::parse('2026-01-10 16:00:00'),
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: Carbon::parse('2026-01-10 17:00:00'),
    );
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 1001,
        'game_id' => $game->id,
        'model_run_id' => $inferenceRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => ['home_elo' => 1550],
        'outputs' => ['baseline_win_probability' => 0.57],
        'feature_hash' => str_repeat('e', 64),
        'generated_at' => Carbon::parse('2026-01-10 17:00:00'),
        'game_start_at' => $gameStart,
        'features_available_at' => Carbon::parse('2026-01-10 16:55:00'),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 1001,
        'market_type' => 'win_probability',
        'baseline_output' => 0.57,
        'challenger_output' => 0.62,
        'output_delta' => 0.05,
        'status' => 'promoted_shadow',
        'generated_at' => Carbon::parse('2026-01-10 17:00:00'),
    ]);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'commence_time' => $gameStart,
        'captured_at' => Carbon::parse('2026-01-10 16:55:00'),
        'payload_hash' => str_repeat('f', 64),
        'odds_data' => [],
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'bookmaker_key' => 'testbook',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 120,
        'implied_probability' => 0.454545,
        'no_vig_probability' => 0.45,
        'commence_time' => $gameStart,
        'captured_at' => Carbon::parse('2026-01-10 16:55:00'),
        'is_pregame' => true,
        'quote_hash' => str_repeat('1', 64),
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'bookmaker_key' => 'testbook',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 100,
        'implied_probability' => 0.5,
        'no_vig_probability' => 0.50,
        'commence_time' => $gameStart,
        'captured_at' => Carbon::parse('2026-01-10 18:55:00'),
        'is_pregame' => true,
        'quote_hash' => str_repeat('2', 64),
    ]);

    Artisan::call('sports:record-shadow-bet-decisions', [
        '--sport' => 'nba',
        '--artifact' => $artifact->id,
    ]);

    $decision = BetDecision::query()->where('shadow_model_output_id', $shadow->id)->firstOrFail();
    expect($decision->is_bet)->toBeTrue()
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->price)->toBe(120)
        ->and($decision->eligibility_reasons)->toBe([]);

    Artisan::call('sports:settle-bet-decisions', ['--sport' => 'nba']);
    $decision->refresh()->load('settlement');

    expect($decision->settlement)->not->toBeNull()
        ->and($decision->settlement->result_status)->toBe('win')
        ->and((float) $decision->settlement->profit_units)->toBe(1.2)
        ->and((float) $decision->settlement->clv)->toBe(0.05);

    Artisan::call('sports:report-model-feedback', ['artifact' => $artifact->id]);

    expect(Artisan::output())->toContain('Actual ROI')
        ->toContain('Counterfactual ROI')
        ->toContain('Average CLV probability')
        ->toContain('Challenger Brier');
});

it('never turns a historical shadow output into a retrospective bet', function () {
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'training',
        modelVersion: 'nba-calibration-v1',
        featureVersion: 'trusted-snapshot-v1',
        blendVersion: 'challenger-shadow-v1',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nba',
        'market_type' => 'win_probability',
        'model_type' => 'platt_calibration',
        'model_version' => 'nba-calibration-v1',
        'feature_version' => 'trusted-snapshot-v1',
        'dataset_hash' => str_repeat('3', 64),
        'artifact_path' => storage_path('app/ml/models/test-retrospective.json'),
        'artifact_hash' => str_repeat('4', 64),
        'status' => 'promoted',
        'promoted_at' => Carbon::parse('2026-01-10 18:00:00'),
    ]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = Carbon::parse('2026-01-10 19:00:00');
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_FINAL',
        'home_score' => 105,
        'away_score' => 100,
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nba',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: Carbon::parse('2026-01-10 17:00:00'),
    );
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 2001,
        'game_id' => $game->id,
        'model_run_id' => $inferenceRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'generated_at' => Carbon::parse('2026-01-10 17:00:00'),
        'game_start_at' => $gameStart,
        'features_available_at' => Carbon::parse('2026-01-10 17:00:00'),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'prediction_table' => 'nba_predictions',
        'prediction_id' => 2001,
        'market_type' => 'win_probability',
        'baseline_output' => 0.55,
        'challenger_output' => 0.65,
        'output_delta' => 0.10,
        'status' => 'promoted_shadow',
        'generated_at' => Carbon::parse('2026-01-10 17:00:00'),
    ]);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'commence_time' => $gameStart,
        'captured_at' => Carbon::parse('2026-01-10 16:55:00'),
        'payload_hash' => str_repeat('5', 64),
        'odds_data' => [],
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 110,
        'no_vig_probability' => 0.45,
        'captured_at' => Carbon::parse('2026-01-10 16:55:00'),
        'is_pregame' => true,
        'quote_hash' => str_repeat('6', 64),
    ]);

    Artisan::call('sports:record-shadow-bet-decisions', [
        '--sport' => 'nba',
        '--artifact' => $artifact->id,
    ]);

    $decision = BetDecision::query()->where('shadow_model_output_id', $shadow->id)->firstOrFail();
    expect($decision->is_bet)->toBeFalse()
        ->and($decision->eligibility_reasons)->toContain('artifact_not_promoted_at_decision_time');
});
