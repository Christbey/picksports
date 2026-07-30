<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses()->group('mlb', 'commands');

it('exports only trusted canonical mlb snapshots in chronological order', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    $earlyGame = createMlbExportGame($home, $away, '2026-07-01 19:10:00');
    $lateGame = createMlbExportGame($home, $away, '2026-07-02 19:10:00');
    $earlyPrediction = createMlbExportPredictionAndEvaluation($earlyGame);
    $latePrediction = createMlbExportPredictionAndEvaluation($lateGame);

    createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 10:00:00',
        featureValue: 1,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );
    $canonicalObserved = createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 18:30:00',
        featureValue: 2,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );
    createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 20:00:00',
        featureValue: 99,
        availabilityStatus: 'after_game_start',
        pregameSafe: false,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    $canonicalReconstruction = createMlbExportSnapshot(
        $lateGame,
        $latePrediction,
        $modelRun->id,
        generatedAt: '2026-07-20 12:00:00',
        featureValue: 3,
        availabilityStatus: 'verified_reconstruction',
        pregameSafe: true,
        lineage: [
            'prediction_horizon' => 'Final Pregame',
            'point_in_time_verified' => true,
            'verification_method' => 'source_timestamp_audit',
        ],
        featuresAvailableAt: '2026-07-02 19:10:00',
    );
    createMlbExportSnapshot(
        $lateGame,
        $latePrediction,
        $modelRun->id,
        generatedAt: '2026-07-21 12:00:00',
        featureValue: 98,
        availabilityStatus: 'verified_reconstruction',
        pregameSafe: true,
        lineage: [
            'prediction_horizon' => 'Final Pregame',
            'point_in_time_verified' => false,
        ],
        featuresAvailableAt: '2026-07-02 19:10:00',
    );

    $path = storage_path('app/ml/tests/mlb_trusted_export.csv');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'stale export');

    $exitCode = Artisan::call('mlb:export-training-data', [
        '--season' => [2026],
        '--path' => $path,
    ]);

    $rows = readMlbExportCsv($path);

    expect($exitCode)->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and(array_column($rows, 'game_id'))->toBe([
            (string) $earlyGame->id,
            (string) $lateGame->id,
        ])
        ->and(array_column($rows, 'canonical_snapshot_id'))->toBe([
            (string) $canonicalObserved->id,
            (string) $canonicalReconstruction->id,
        ])
        ->and(array_column($rows, 'feature_signal'))->toBe(['2', '3'])
        ->and(array_column($rows, 'prediction_horizon'))->toBe(['final_pregame', 'final_pregame'])
        ->and($rows[0]['availability_status'])->toBe('observed_pregame')
        ->and($rows[1]['availability_status'])->toBe('verified_reconstruction')
        ->and($rows[0]['pregame_safe'])->toBe('1')
        ->and($rows[0]['target_home_win'])->toBe('1')
        ->and($rows[0]['target_home_margin'])->toBe('2')
        ->and($rows[0]['target_total_points'])->toBe('8')
        ->and($rows[0]['config_hash'])->toHaveLength(64)
        ->and($rows[0]['feature_schema_hash'])->toHaveLength(64)
        ->and($rows[0]['target_hash'])->toHaveLength(64)
        ->and($rows[0]['row_lineage_hash'])->toHaveLength(64)
        ->and($rows[0]['dataset_schema_hash'])->toHaveLength(64)
        ->and(mlbExportTemporaryFiles($path))->toBe([]);
});

it('exports only the latest trusted snapshot when a game has multiple horizons', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPredictionAndEvaluation($game);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-04 10:00:00',
        featureValue: 1,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Morning'],
    );
    $latest = createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-04 18:30:00',
        featureValue: 2,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    $path = storage_path('app/ml/tests/mlb_single_game_export.csv');
    @unlink($path);

    Artisan::call('mlb:export-training-data', ['--path' => $path]);

    $rows = readMlbExportCsv($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['canonical_snapshot_id'])->toBe((string) $latest->id)
        ->and($rows[0]['prediction_horizon'])->toBe('final_pregame')
        ->and($rows[0]['feature_signal'])->toBe('2');
});

it('requires an explicit research override to export unsafe mlb snapshots', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = createMlbExportGame($home, $away, '2026-07-03 19:10:00');
    $prediction = createMlbExportPredictionAndEvaluation($game);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-03 20:00:00',
        featureValue: 8,
        availabilityStatus: 'after_game_start',
        pregameSafe: false,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    $path = storage_path('app/ml/tests/mlb_unsafe_export.csv');
    @unlink($path);

    $exitCode = Artisan::call('mlb:export-training-data', ['--path' => $path]);

    expect($exitCode)->toBe(1)
        ->and(file_exists($path))->toBeFalse()
        ->and(Artisan::output())->toContain('No MLB rows met');

    Artisan::call('mlb:export-training-data', [
        '--path' => $path,
        '--include-unsafe' => true,
    ]);

    $rows = readMlbExportCsv($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['pregame_safe'])->toBe('0')
        ->and($rows[0]['availability_status'])->toBe('after_game_start')
        ->and(Artisan::output())->toContain('no (research override)');
});

it('fails an empty mlb export and removes a stale destination', function () {
    $path = storage_path('app/ml/tests/mlb_empty_export.csv');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'stale export');

    $exitCode = Artisan::call('mlb:export-training-data', [
        '--season' => [1900],
        '--path' => $path,
    ]);

    expect($exitCode)->toBe(1)
        ->and(file_exists($path))->toBeFalse()
        ->and(mlbExportTemporaryFiles($path))->toBe([])
        ->and(Artisan::output())->toContain('No MLB rows met');
});

function createMlbExportGame(Team $home, Team $away, string $startAt): Game
{
    $start = Carbon::parse($startAt);

    return Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => $start->toDateString(),
        'game_time' => $start->format('H:i:s'),
        'home_score' => 5,
        'away_score' => 3,
    ]);
}

function createMlbExportPredictionAndEvaluation(Game $game): Prediction
{
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 57,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
    ]);

    PredictionEvaluation::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'actuals' => [
            'actual_spread' => 2.0,
            'actual_total' => 8.0,
        ],
        'errors' => [],
        'market_comparison' => [],
        'evaluated_at' => now(),
    ]);

    return $prediction;
}

/**
 * @param  array<string, mixed>  $lineage
 */
function createMlbExportSnapshot(
    Game $game,
    Prediction $prediction,
    string $modelRunId,
    string $generatedAt,
    int $featureValue,
    string $availabilityStatus,
    bool $pregameSafe,
    array $lineage,
    ?string $featuresAvailableAt = null,
): PredictionFeatureSnapshot {
    $gameStart = Carbon::parse($game->game_date->toDateString().' '.$game->game_time);

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => ['signal' => $featureValue],
        'outputs' => [
            'predicted_spread' => 1.5,
            'predicted_total' => 8.5,
            'win_probability' => 0.57,
        ],
        'market_context' => ['vegas_spread' => -1.5],
        'feature_hash' => hash('sha256', 'feature-'.$game->id.'-'.$featureValue),
        'generated_at' => Carbon::parse($generatedAt),
        'game_start_at' => $gameStart,
        'features_available_at' => Carbon::parse($featuresAvailableAt ?? $generatedAt),
        'pregame_safe' => $pregameSafe,
        'availability_status' => $availabilityStatus,
        'source_timestamps' => ['features' => $featuresAvailableAt ?? $generatedAt],
        'lineage_metadata' => $lineage,
    ]);
}

/**
 * @return list<array<string, string>>
 */
function readMlbExportCsv(string $path): array
{
    $csv = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
    $headers = array_shift($csv);

    return array_map(
        fn (array $row): array => array_combine($headers, $row),
        $csv,
    );
}

/**
 * @return list<string>
 */
function mlbExportTemporaryFiles(string $path): array
{
    return glob(dirname($path).'/.'.basename($path).'.*.tmp') ?: [];
}
