<?php

namespace Tests\Support;

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit synthetic source/capture writer, never an inferred certification of retained rows. */
final class ObservedMlbDatasetFixture
{
    public static function create(bool $retainDataset = false, string $gameDate = '2026-07-02', int $limit = 0,
        int $season = 2026, array $exportSeasons = [], ?CarbonInterface $finalizedAt = null): array
    {
        $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
            'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
            'season' => $season, 'season_type' => '2', 'game_date' => $gameDate, 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
        ]);
        $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5,
            'win_probability' => 0.57, 'confidence_score' => 57]);
        $snapshot = app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
            'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57], 'market_context' => [],
        ]);
        $path = storage_path('framework/testing/mlb-fenced-dataset-'.Str::uuid().'.csv');
        $priorClock = Date::getTestNow();
        $complete = false;
        if ($finalizedAt !== null) {
            Date::setTestNow($finalizedAt);
        }
        try {
            $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 3]);
            $evaluation = PredictionEvaluation::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $snapshot->prediction_id,
                'game_id' => $game->id, 'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version, 'blend_version' => $snapshot->blend_version,
                'actuals' => ['actual_spread' => 2, 'actual_total' => 8], 'errors' => [], 'market_comparison' => [], 'evaluated_at' => now()]);
            $arguments = ['--path' => $path];
            if ($limit > 0) {
                $arguments['--limit'] = $limit;
            }
            if ($exportSeasons !== []) {
                $arguments['--season'] = $exportSeasons;
            }
            if (Artisan::call('mlb:export-training-data', $arguments) !== 0) {
                throw new RuntimeException(Artisan::output());
            }
            $proof = app(MlbTabularDatasetAdmission::class)->inspect($path);
            $complete = true;
        } finally {
            if (! $retainDataset || ! $complete) {
                @unlink($path);
                @unlink($path.'.manifest.json');
            }
            if ($finalizedAt !== null) {
                Date::setTestNow($priorClock);
            }
        }

        return compact('game', 'prediction', 'snapshot', 'evaluation', 'proof', 'path');
    }
}
