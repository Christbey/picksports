<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Facades\DB;

class NflPredictionPublisher
{
    /** @return array{0: Prediction, 1: PredictionFeatureSnapshot} */
    public function publish(Game $game, array $predictionData, array $snapshotData): array
    {
        return DB::transaction(function () use ($game, $predictionData, $snapshotData): array {
            $prediction = Prediction::query()->updateOrCreate(['game_id' => $game->id], $predictionData);
            $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record(
                $prediction, $game, 'nfl', $predictionData, $snapshotData,
            );

            return [$prediction, $snapshot];
        });
    }
}
