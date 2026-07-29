<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\NFL\NflModelMonitoringService;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('runs the promoted full-historical nfl profile as a tracking-only shadow challenger', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    config([
        'ml.promotion.live_shadow.minimum_observations' => 0,
        'ml.promotion.live_shadow.minimum_settled_decisions' => 0,
    ]);

    $datasetPath = storage_path('app/ml/tests/nfl_full_historical_shadow.csv');
    $reportPath = storage_path('app/ml/tests/nfl_full_historical_shadow_report.json');
    $artifactPath = storage_path('app/ml/tests/nfl_full_historical_shadow.json');
    File::ensureDirectoryExists(dirname($datasetPath));
    File::put($datasetPath, "game_id,target_home_win\n1,1\n");
    File::put($reportPath, json_encode([
        'report_type' => 'nfl_historical_profile_rolling_season_comparison',
        'challenger' => [
            'path' => $datasetPath,
            'hash' => hash_file('sha256', $datasetPath),
        ],
        'summary' => [
            'matched_games' => 400,
            'window_count' => 4,
            'challenger_better_window_count' => 4,
            'avg_brier_delta' => -0.015,
            'avg_log_loss_delta' => -0.025,
            'avg_spread_mae_delta' => -0.4,
        ],
        'windows' => [
            ['evaluation_season' => 2022, 'games' => 100, 'brier_delta' => -0.012, 'log_loss_delta' => -0.02, 'spread_mae_delta' => -0.3],
            ['evaluation_season' => 2023, 'games' => 100, 'brier_delta' => -0.018, 'log_loss_delta' => -0.03, 'spread_mae_delta' => -0.5],
            ['evaluation_season' => 2024, 'games' => 100, 'brier_delta' => -0.014, 'log_loss_delta' => -0.02, 'spread_mae_delta' => -0.4],
            ['evaluation_season' => 2025, 'games' => 100, 'brier_delta' => -0.016, 'log_loss_delta' => -0.03, 'spread_mae_delta' => -0.4],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('nfl:register-historical-profile-artifact', [
        '--dataset' => $datasetPath,
        '--report' => $reportPath,
        '--output' => $artifactPath,
    ]);

    $artifact = ModelArtifact::query()
        ->where('model_type', 'nfl_full_historical_profile')
        ->firstOrFail();

    expect(Artisan::output())->toContain('NFL historical profile challenger registered')
        ->and($artifact->artifact_hash)->toBe(hash_file('sha256', $artifactPath))
        ->and($artifact->trainingRun->config_hash)->toHaveLength(64)
        ->and(File::exists($artifact->artifact_path))->toBeTrue();

    Artisan::call('sports:evaluate-model-promotion', [
        'artifact' => $artifact->id,
        '--report' => $reportPath,
        '--promote' => true,
    ]);
    expect($artifact->refresh()->status)->toBe('promoted');

    config([
        'nfl.predictions.full_historical_shadow.enabled' => true,
        'nfl.predictions.full_historical_shadow.profile' => 'full-historical',
        'nfl.predictions.full_historical_shadow.artifact_path' => $artifactPath,
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
        'nfl.predictions.total_environment.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_point_calibration.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
        'nfl.predictions.automated_calibration_tweaks.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
    ]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'week' => 1,
        'game_date' => '2026-09-10',
        'game_time' => '19:20:00',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
        'neutral_site' => false,
    ])->fresh(['homeTeam', 'awayTeam']);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game);

    $prediction = $game->prediction()->firstOrFail();
    $snapshot = PredictionFeatureSnapshot::query()
        ->where('sport', 'nfl')
        ->where('prediction_id', $prediction->id)
        ->latest('id')
        ->firstOrFail();
    $shadow = ShadowModelOutput::query()
        ->where('model_artifact_id', $artifact->id)
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->firstOrFail();

    expect((float) $prediction->win_probability)->toBe((float) data_get($snapshot->outputs, 'baseline_win_probability'))
        ->and(data_get($prediction->model_metadata, 'shadow_inference.artifact_id'))->toBe($artifact->id)
        ->and(data_get($prediction->model_metadata, 'shadow_inference.public_output_changed'))->toBeFalse()
        ->and(data_get($snapshot->outputs, 'active_source'))->toBe('baseline')
        ->and($snapshot->pregame_safe)->toBeTrue()
        ->and($shadow->sport)->toBe('nfl')
        ->and($shadow->status)->toBe('promoted_shadow')
        ->and((float) $shadow->baseline_output)->toBe((float) $prediction->win_probability);

    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'source' => 'test',
        'commence_time' => Carbon::parse('2026-09-10 19:20:00'),
        'captured_at' => now()->subMinute(),
        'payload_hash' => hash('sha256', 'nfl-shadow-'.$game->id),
        'odds_data' => [],
    ]);
    $challengerHomeProbability = (float) $shadow->challenger_output;
    $side = $challengerHomeProbability >= 0.5 ? 'home' : 'away';
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'source' => 'test',
        'bookmaker_key' => 'testbook',
        'market_key' => 'h2h',
        'side' => $side,
        'price' => 120,
        'implied_probability' => 0.454545,
        'no_vig_probability' => 0.10,
        'commence_time' => Carbon::parse('2026-09-10 19:20:00'),
        'captured_at' => now()->subMinute(),
        'is_pregame' => true,
        'quote_hash' => hash('sha256', 'nfl-shadow-quote-'.$game->id),
    ]);

    Artisan::call('sports:record-shadow-bet-decisions', [
        '--sport' => 'nfl',
        '--artifact' => $artifact->id,
    ]);

    $decision = BetDecision::query()
        ->where('shadow_model_output_id', $shadow->id)
        ->firstOrFail();

    expect($decision->is_bet)->toBeTrue()
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->status)->toBe('tracking_bet')
        ->and($decision->eligibility_reasons)->toBe([]);

    Artisan::call('sports:settle-bet-decisions', ['--sport' => 'nfl']);

    expect(BetSettlement::query()->where('bet_decision_id', $decision->id)->exists())->toBeFalse()
        ->and(Artisan::output())->toContain('Settled 0 decision(s).');
});

it('records safe no-bets for missing quotes and excessive model uncertainty', function () {
    Carbon::setTestNow('2026-09-01 12:00:00');
    config(['nfl_ml.shadow.max_uncertainty' => 0.08]);

    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-shadow-safety-v1',
        featureVersion: 'nfl-pregame-ml-v3',
        blendVersion: 'nfl-tabular-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nfl',
        'market_type' => 'multi_market',
        'model_type' => 'nfl_tabular_bundle',
        'model_version' => 'nfl-shadow-safety-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'dataset_hash' => str_repeat('d', 64),
        'artifact_path' => storage_path('app/ml/tests/nfl-shadow-safety.zip'),
        'artifact_hash' => str_repeat('e', 64),
        'status' => 'promoted',
        'promotion_decision' => [
            'promoted_markets' => ['win_probability'],
        ],
        'promoted_at' => now()->subHours(2),
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: now()->subHour(),
    );
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = Carbon::parse('2026-09-10 19:20:00');
    $createShadow = function (float $uncertainty) use (
        $artifact,
        $inferenceRun,
        $home,
        $away,
        $gameStart,
    ): array {
        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => 2026,
            'season_type' => 2,
            'week' => 1,
            'game_date' => $gameStart->toDateString(),
            'game_time' => $gameStart->format('H:i:s'),
            'status' => 'STATUS_SCHEDULED',
        ]);
        $snapshot = PredictionFeatureSnapshot::query()->create([
            'sport' => 'nfl',
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => $game->id,
            'game_id' => $game->id,
            'model_run_id' => $inferenceRun->id,
            'model_version' => 'rules-v1',
            'feature_version' => 'nfl-pregame-ml-v3',
            'blend_version' => 'baseline-v1',
            'features' => ['home_elo' => 1550],
            'outputs' => ['challenger_uncertainty' => $uncertainty],
            'feature_hash' => hash('sha256', 'shadow-safety-'.$game->id),
            'generated_at' => now()->subHour(),
            'game_start_at' => $gameStart,
            'features_available_at' => now()->subHour(),
            'pregame_safe' => true,
            'availability_status' => 'observed_pregame',
        ]);
        $shadow = ShadowModelOutput::query()->create([
            'inference_run_id' => $inferenceRun->id,
            'model_artifact_id' => $artifact->id,
            'prediction_feature_snapshot_id' => $snapshot->id,
            'sport' => 'nfl',
            'game_table' => 'nfl_games',
            'game_id' => $game->id,
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => $game->id,
            'market_type' => 'win_probability',
            'baseline_output' => 0.50,
            'challenger_output' => 0.65,
            'output_delta' => 0.15,
            'status' => 'promoted_shadow',
            'explanation' => [
                'challenger_outputs' => ['uncertainty' => $uncertainty],
            ],
            'generated_at' => now()->subHour(),
        ]);

        return [$game, $shadow];
    };

    [$gameWithoutQuote, $shadowWithoutQuote] = $createShadow(0.05);
    [$gameWithUncertainty, $shadowWithUncertainty] = $createShadow(0.12);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $gameWithUncertainty->id,
        'source' => 'test',
        'commence_time' => $gameStart,
        'captured_at' => now()->subHours(2),
        'payload_hash' => hash('sha256', 'uncertainty-odds-'.$gameWithUncertainty->id),
        'odds_data' => [],
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $gameWithUncertainty->id,
        'source' => 'test',
        'bookmaker_key' => 'testbook',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 120,
        'implied_probability' => 0.454545,
        'no_vig_probability' => 0.45,
        'commence_time' => $gameStart,
        'captured_at' => now()->subHours(2),
        'is_pregame' => true,
        'quote_hash' => hash('sha256', 'uncertainty-quote-'.$gameWithUncertainty->id),
    ]);

    Artisan::call('sports:record-shadow-bet-decisions', [
        '--sport' => 'nfl',
        '--artifact' => $artifact->id,
    ]);

    $missingQuoteDecision = BetDecision::query()
        ->where('shadow_model_output_id', $shadowWithoutQuote->id)
        ->firstOrFail();
    $uncertaintyDecision = BetDecision::query()
        ->where('shadow_model_output_id', $shadowWithUncertainty->id)
        ->firstOrFail();

    expect($missingQuoteDecision->is_bet)->toBeFalse()
        ->and($missingQuoteDecision->status)->toBe('shadow_no_bet')
        ->and($missingQuoteDecision->eligibility_reasons)->toContain('pregame_market_quote_missing')
        ->and($missingQuoteDecision->edge)->toBeNull()
        ->and($missingQuoteDecision->price)->toBeNull()
        ->and($uncertaintyDecision->is_bet)->toBeFalse()
        ->and($uncertaintyDecision->is_public)->toBeFalse()
        ->and($uncertaintyDecision->is_tracking_only)->toBeTrue()
        ->and($uncertaintyDecision->eligibility_reasons)->toContain('model_uncertainty_above_threshold')
        ->and(data_get($uncertaintyDecision->explanation, 'model_uncertainty'))->toBe(0.12)
        ->and(data_get($uncertaintyDecision->explanation, 'maximum_model_uncertainty'))->toBe(0.08);

    expect($gameWithoutQuote->exists)->toBeTrue();
});

it('shows canonical Python evaluation windows and promotion summary on NFL monitoring', function () {
    config(['nfl_ml.shadow.max_uncertainty' => null]);

    $reportPath = storage_path('app/ml/tests/nfl-monitoring-python-evaluation.json');
    File::ensureDirectoryExists(dirname($reportPath));
    File::put($reportPath, json_encode([
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
            'recommendation' => 'eligible_for_live_shadow',
        ],
    ], JSON_THROW_ON_ERROR));
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-monitoring-v1',
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
        'model_version' => 'nfl-monitoring-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'dataset_hash' => str_repeat('f', 64),
        'artifact_path' => storage_path('app/ml/tests/nfl-monitoring.zip'),
        'artifact_hash' => str_repeat('1', 64),
        'evaluation_report_path' => $reportPath,
        'evaluation_report_hash' => hash_file('sha256', $reportPath),
        'status' => 'challenger',
    ]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = now()->addDay();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 7001,
        'game_id' => $game->id,
        'model_run_id' => $trainingRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'nfl-pregame-ml-v3',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'feature_hash' => str_repeat('2', 64),
        'generated_at' => now(),
        'game_start_at' => $gameStart,
        'features_available_at' => now(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $trainingRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 7001,
        'market_type' => 'win_probability',
        'baseline_output' => 0.55,
        'challenger_output' => 0.62,
        'output_delta' => 0.07,
        'status' => 'shadow',
        'explanation' => [
            'challenger_outputs' => ['uncertainty' => 0.07],
        ],
        'generated_at' => now(),
    ]);
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $trainingRun->id,
        'model_artifact_id' => $artifact->id,
        'shadow_model_output_id' => $shadow->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => $shadow->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 7001,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'status' => 'shadow_no_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => false,
        'pregame_safe' => true,
        'decided_at' => now(),
        'game_start_at' => $gameStart,
        'decision_hash' => str_repeat('3', 64),
    ]);
    BetSettlement::query()->create([
        'bet_decision_id' => $decision->id,
        'result_status' => 'win',
        'result_value' => 7,
        'profit_units' => 0,
        'graded_at' => now(),
        'settled_at' => now(),
    ]);

    $dashboard = app(NflModelMonitoringService::class)->dashboard($artifact->id);
    $window = $dashboard['evaluation_windows'][0];

    expect(data_get($dashboard, 'artifact.evaluation_summary.avg_brier_delta'))->toBe(0.02)
        ->and(data_get($dashboard, 'artifact.promotion_summary.recommendation'))->toBe('eligible_for_live_shadow')
        ->and(data_get($dashboard, 'artifact.delta_convention.reported'))->toBe('baseline_minus_challenger')
        ->and(data_get($dashboard, 'summary.brier_delta'))->toBe(0.0581)
        ->and(data_get($dashboard, 'summary.delta_convention'))->toBe('baseline_minus_challenger')
        ->and(data_get($dashboard, 'observations.0.challenger_outputs.uncertainty'))->toBe(0.07)
        ->and(data_get($dashboard, 'observations.0.decision.explanation.model_uncertainty'))->toBe(0.07)
        ->and(data_get($dashboard, 'observations.0.decision.explanation.uncertainty_gate_enabled'))->toBeFalse()
        ->and($window)->toMatchArray([
            'evaluation_season' => 2025,
            'games' => 272,
            'baseline_brier' => 0.24,
            'challenger_brier' => 0.22,
            'brier_delta' => 0.02,
            'log_loss_delta' => 0.03,
            'spread_mae_delta' => 0.8,
            'total_mae_delta' => -0.2,
            'delta_convention' => 'baseline_minus_challenger',
        ]);
});
