<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\Artisan;

it('exports nba training data from snapshots and evaluations', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 108,
        'away_score' => 100,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.5,
        'predicted_total' => 210.5,
        'win_probability' => 0.63,
        'confidence_score' => 70,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
    ]);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'nba',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v1',
        blendVersion: 'baseline-v1',
    );

    PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_run_id' => $modelRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_recent_form' => 4.2,
            'away_recent_form' => -1.1,
        ],
        'outputs' => [
            'predicted_spread' => 6.5,
            'predicted_total' => 210.5,
            'confidence_score' => 70,
        ],
        'market_context' => [
            'vegas_spread' => 4.5,
        ],
        'model_metadata' => [
            'model' => 'nba_ensemble',
        ],
        'feature_hash' => 'test-hash',
        'generated_at' => now(),
        'game_start_at' => now()->addDay(),
        'features_available_at' => now(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);

    PredictionEvaluation::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'actuals' => [
            'actual_spread' => 8.0,
            'actual_total' => 208.0,
        ],
        'errors' => [
            'spread_error' => 1.5,
            'total_error' => 2.5,
            'winner_correct' => true,
            'brier_score' => 0.1369,
        ],
        'market_comparison' => [
            'model_beats_market_spread' => true,
        ],
        'evaluated_at' => now(),
    ]);

    $path = storage_path('app/ml/test_nba_training_data.csv');
    @unlink($path);

    Artisan::call('nba:export-training-data', [
        '--path' => $path,
        '--season' => 2026,
    ]);

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)->toContain('feature_home_recent_form')
        ->toContain('feature_model_predicted_spread')
        ->toContain('target_home_margin')
        ->toContain('target_hash')
        ->toContain('availability_status');
});

it('reports nba training readiness from snapshots and evaluations', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 111,
        'away_score' => 102,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 5.5,
        'predicted_total' => 214.5,
        'win_probability' => 0.61,
        'confidence_score' => 68,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
    ]);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'nba',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v1',
        blendVersion: 'baseline-v1',
    );

    PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_run_id' => $modelRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => ['home_recent_form' => 3.1],
        'outputs' => ['confidence_score' => 68],
        'generated_at' => now(),
        'game_start_at' => now()->addDay(),
        'features_available_at' => now(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);

    PredictionEvaluation::query()->create([
        'sport' => 'nba',
        'prediction_table' => 'nba_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'actuals' => ['actual_spread' => 9.0],
        'errors' => [
            'spread_error' => 3.5,
            'total_error' => 5.0,
            'winner_correct' => true,
            'brier_score' => 0.1521,
        ],
        'market_comparison' => [
            'model_beats_market_spread' => false,
        ],
        'evaluated_at' => now(),
    ]);

    Artisan::call('nba:report-training-readiness', [
        '--season' => 2026,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('NBA Training Readiness')
        ->toContain('By Model Version')
        ->toContain('rules-v1')
        ->toContain('Point-In-Time Status');
});
