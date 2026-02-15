<?php

namespace App\Http\Controllers\Api\CBB;

use App\Actions\CBB\CalculateBettingValue;
use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\CBB\PredictionResource;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use Illuminate\Database\Eloquent\Collection;

class PredictionController extends AbstractPredictionController
{
    protected function getPredictionModel(): string
    {
        return Prediction::class;
    }

    protected function getGameModel(): string
    {
        return Game::class;
    }

    protected function getPredictionResource(): string
    {
        return PredictionResource::class;
    }

    protected function getGameDateColumn(): string
    {
        return 'game_date';
    }

    protected function returnFirstPredictionOnly(): bool
    {
        return true;
    }

    protected function processPredictions(Collection $predictions): Collection
    {
        // Calculate betting value for each and sort by whether they have value
        $calculator = app(CalculateBettingValue::class);

        $predictionsWithValue = $predictions->map(function ($prediction) use ($calculator) {
            $prediction->betting_value = $calculator->execute($prediction->game);
            $prediction->has_betting_value = ! empty($prediction->betting_value);
            $prediction->betting_value_count = $prediction->has_betting_value ? count($prediction->betting_value) : 0;

            return $prediction;
        });

        // Sort: betting value first, then by date
        return $predictionsWithValue->sortByDesc(function ($prediction) {
            // Games with betting value get priority (1000000 + count), others get timestamp
            return $prediction->has_betting_value
                ? 1000000 + $prediction->betting_value_count
                : $prediction->created_at->timestamp;
        })->values();
    }
}
