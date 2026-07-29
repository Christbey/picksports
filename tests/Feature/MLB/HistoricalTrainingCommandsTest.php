<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses()->group('mlb', 'commands');

it('backfills historical mlb predictions and snapshots for completed regular-season games', function () {
    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
        'abbreviation' => 'CHC',
    ]);
    $away = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'STL',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-06-15',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 5,
        'away_score' => 3,
    ]);

    Artisan::call('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--limit' => 1,
    ]);

    $prediction = Prediction::query()->where('game_id', $game->id)->first();
    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('game_id', $game->id)
        ->first();
    $evaluation = PredictionEvaluation::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('game_id', $game->id)
        ->first();

    expect($prediction)->not->toBeNull()
        ->and($prediction?->feature_version)->toBe('core-v3')
        ->and($prediction?->graded_at)->not->toBeNull()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot?->sport)->toBe('mlb')
        ->and($evaluation)->not->toBeNull()
        ->and($evaluation?->sport)->toBe('mlb');
});

it('exports mlb training data from snapshots and evaluations', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'home_score' => 7,
        'away_score' => 4,
    ]);

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

    $firstRunId = (string) Str::uuid();
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => $firstRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_combined_elo' => 1512.5,
            'away_combined_elo' => 1497.8,
        ],
        'outputs' => [
            'predicted_spread' => 1.5,
            'predicted_total' => 8.5,
            'confidence_score' => 57,
        ],
        'market_context' => [
            'vegas_spread' => -1.5,
        ],
        'model_metadata' => [
            'feature_set' => 'historical-priors',
        ],
        'feature_hash' => 'mlb-test-hash',
        'generated_at' => now(),
    ]);

    $secondRunId = (string) Str::uuid();
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => $secondRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_combined_elo' => 1490.0,
            'away_combined_elo' => 1520.0,
        ],
        'outputs' => [
            'predicted_spread' => -2.0,
            'predicted_total' => 10.0,
            'win_probability' => 0.42,
            'confidence_score' => 58,
        ],
        'market_context' => [
            'vegas_spread' => -1.5,
        ],
        'feature_hash' => 'mlb-test-hash-second-run',
        'generated_at' => now()->addSecond(),
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
            'actual_spread' => 3.0,
            'actual_total' => 11.0,
        ],
        'errors' => [
            'spread_error' => 1.5,
            'total_error' => 2.5,
            'winner_correct' => true,
            'brier_score' => 0.1849,
        ],
        'market_comparison' => [
            'model_beats_market_spread' => true,
        ],
        'evaluated_at' => now(),
    ]);

    $path = storage_path('app/ml/test_mlb_training_data.csv');
    @unlink($path);

    Artisan::call('mlb:export-training-data', [
        '--path' => $path,
        '--season' => [2025],
    ]);

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)->toContain('feature_home_combined_elo')
        ->toContain('output_predicted_spread')
        ->toContain('actual_actual_spread')
        ->toContain('error_brier_score')
        ->toContain('market_model_beats_market_spread');

    $csv = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
    $headers = array_shift($csv);
    $exported = collect($csv)->map(fn (array $row): array => array_combine($headers, $row));

    expect($exported)->toHaveCount(2)
        ->and($exported->pluck('snapshot_run_id')->all())->toBe([$firstRunId, $secondRunId])
        ->and($exported->pluck('error_spread_error')->map(fn (string $value): float => (float) $value)->all())
        ->toBe([1.5, 5.0]);
});
