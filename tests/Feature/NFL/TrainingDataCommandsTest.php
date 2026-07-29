<?php

use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

it('exports, splits, trains, and rolling-evaluates trusted nfl snapshots', function () {
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'nfl',
        modelVersion: 'elo-v1-full-historical',
        featureVersion: 'historical-core-v1',
        blendVersion: 'historical-v1-full-historical',
        metadata: [
            'run_type' => 'historical_reconstruction',
            'parameters' => ['historical_profile' => 'full-historical'],
        ],
    );
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    foreach (range(1, 8) as $index) {
        $gameStart = Carbon::parse('2025-09-01 12:00:00')->addWeeks($index);
        $homeWon = $index % 3 !== 0;
        $game = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'season' => 2017 + $index,
            'season_type' => 2,
            'week' => $index,
            'game_date' => $gameStart->toDateString(),
            'game_time' => $gameStart->format('H:i:s'),
            'status' => 'STATUS_FINAL',
            'home_score' => $homeWon ? 27 : 17,
            'away_score' => $homeWon ? 20 : 24,
        ]);

        PredictionFeatureSnapshot::query()->create([
            'sport' => 'nfl',
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => 3000 + $index,
            'game_id' => $game->id,
            'model_run_id' => $modelRun->id,
            'model_version' => 'elo-v1-full-historical',
            'feature_version' => 'historical-core-v1',
            'blend_version' => 'historical-v1-full-historical',
            'features' => [
                'home_elo' => 1500 + ($index * 5),
                'away_elo' => 1500,
            ],
            'outputs' => [
                'predicted_spread' => 2.5,
                'predicted_total' => 44.0,
                'win_probability' => 0.52 + ($index * 0.01),
                'confidence_score' => 60,
            ],
            'feature_hash' => hash('sha256', "nfl-test-{$index}"),
            'generated_at' => $gameStart,
            'game_start_at' => $gameStart,
            'features_available_at' => $gameStart,
            'pregame_safe' => true,
            'availability_status' => 'verified_reconstruction',
            'lineage_metadata' => [
                'historical_profile' => 'full-historical',
                'point_in_time_basis' => 'verified_reconstruction',
            ],
        ]);
    }

    $datasetPath = storage_path('app/ml/tests/nfl_training_data.csv');
    $splitPath = storage_path('app/ml/tests/nfl-splits');
    $artifactPath = storage_path('app/ml/tests/nfl_calibration.json');
    $reportPath = storage_path('app/ml/tests/nfl_rolling.json');

    Artisan::call('nfl:export-training-data', [
        '--profile' => 'full-historical',
        '--from-season' => 2018,
        '--to-season' => 2025,
        '--feature-version' => 'historical-core-v1',
        '--path' => $datasetPath,
    ]);
    expect(Artisan::output())->toContain('Rows: 8')
        ->toContain('Seasons: 2018 through 2025')
        ->toContain('Feature version: historical-core-v1')
        ->and(file_get_contents($datasetPath))->toContain('target_hash')
        ->toContain('config_hash')
        ->toContain('full-historical');

    Artisan::call('nfl:split-snapshot-dataset', [
        '--input' => $datasetPath,
        '--output-dir' => $splitPath,
        '--train' => 50,
        '--validation' => 25,
        '--test' => 25,
    ]);
    expect(Artisan::output())->toContain('Train rows: 4')
        ->toContain('Validation rows: 2')
        ->toContain('Test rows: 2');

    Artisan::call('nfl:train-win-probability-calibration-model', [
        '--input-dir' => $splitPath,
        '--output' => $artifactPath,
        '--iterations' => 20,
    ]);
    $artifact = ModelArtifact::query()->where('sport', 'nfl')->firstOrFail();
    expect(Artisan::output())->toContain('NFL win-probability challenger trained and registered')
        ->and($artifact->artifact_hash)->toBe(hash_file('sha256', $artifactPath))
        ->and($artifact->trainingRun->config_hash)->toHaveLength(64);

    Artisan::call('nfl:evaluate-win-probability-calibration-rolling', [
        '--input' => $datasetPath,
        '--output' => $reportPath,
        '--artifact-id' => $artifact->id,
        '--min-train-size' => 3,
        '--test-window-size' => 2,
        '--step-size' => 1,
        '--iterations' => 20,
    ]);

    expect(Artisan::output())->toContain('NFL rolling-season calibration evaluation completed')
        ->and(file_exists($reportPath))->toBeTrue()
        ->and($artifact->refresh()->evaluation_report_hash)->toBe(hash_file('sha256', $reportPath));

    Artisan::call('nfl:compare-historical-profiles', [
        '--baseline' => $datasetPath,
        '--challenger' => $datasetPath,
        '--output' => storage_path('app/ml/tests/nfl_profile_comparison.json'),
    ]);
    expect(Artisan::output())->toContain('NFL historical profile rolling-season comparison completed')
        ->toContain('Matched games')
        ->toContain('Held-out seasons');
});
