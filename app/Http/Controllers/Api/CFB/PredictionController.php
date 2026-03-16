<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\CFB\PredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;

    protected function applyIndexFilters($query): void
    {
        $request = request();

        if ($request->filled('season') && $this->hasGameSeasonColumn()) {
            $query->whereHas('game', fn ($q) => $q->where($this->getGameSeasonColumn(), $request->input('season')));
        }

        if ($request->filled('season_type') && $this->hasGameSeasonTypeColumn()) {
            $query->whereHas('game', fn ($q) => $q->where($this->getGameSeasonTypeColumn(), $request->input('season_type')));
        }

        if ($request->filled('week')) {
            $seasonType = (string) $request->input('season_type', '');
            $week = $request->input('week');

            $query->whereHas('game', function ($q) use ($seasonType, $week) {
                if (in_array($seasonType, ['3', 'Postseason'], true) && $this->hasGameColumn('postseason_round')) {
                    $q->where('postseason_round', $week);

                    return;
                }

                if ($this->hasGameWeekColumn()) {
                    $q->where($this->getGameWeekColumn(), $week);
                }
            });
        }

        if ($request->filled('from_date')) {
            $query->whereHas('game', fn ($q) => $q->whereDate($this->getGameDateColumn(), '>=', $request->input('from_date')));
        }

        if ($request->filled('to_date')) {
            $query->whereHas('game', fn ($q) => $q->whereDate($this->getGameDateColumn(), '<=', $request->input('to_date')));
        }
    }
}
