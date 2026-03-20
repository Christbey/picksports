<?php

namespace App\Http\Controllers\Api\CBB;

use App\Actions\CBB\CalculateBettingValue;
use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\CBB\PredictionResource;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;

    protected function returnFirstPredictionOnly(): bool
    {
        return true;
    }

    protected function processPredictions(Collection $predictions): Collection
    {
        $predictions = $predictions->filter(function ($prediction) {
            $game = $prediction->game;

            return $game && ! $this->isPlaceholderGame($game);
        })->values();

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

    private function isPlaceholderGame(Game $game): bool
    {
        return $this->isPlaceholderTeam($game->homeTeam)
            || $this->isPlaceholderTeam($game->awayTeam)
            || str_starts_with((string) ($game->espn_event_id ?? ''), 'placeholder:');
    }

    private function isPlaceholderTeam(?Model $team): bool
    {
        if (! $team) {
            return true;
        }

        $school = strtoupper(trim((string) ($team->school ?? '')));
        $abbreviation = strtoupper(trim((string) ($team->abbreviation ?? '')));
        $espnId = (int) ($team->espn_id ?? 0);

        return in_array($school, ['TBD', 'TBD2'], true)
            || in_array($abbreviation, ['TBD', 'TBD2', 'WFF', 'FF'], true)
            || $espnId < 0;
    }
}
