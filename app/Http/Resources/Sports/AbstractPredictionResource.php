<?php

namespace App\Http\Resources\Sports;

use App\Services\Predictions\PredictionNarrativeService;
use App\Support\PredictionFieldAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractPredictionResource extends JsonResource
{
    /**
     * @return array<int, string>
     */
    protected function liveStatuses(): array
    {
        return ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];
    }

    protected function isLivePrediction(): bool
    {
        return ! $this->relationLoaded('game')
            || in_array($this->game?->status, $this->liveStatuses(), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePredictionData(string $gameResourceClass): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'game' => $gameResourceClass::make($this->whenLoaded('game')),
        ];
    }

    protected function hasTierPermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        return app(PredictionFieldAccess::class)->canViewField($user, $permission);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendStandardTimestamps(array $data): array
    {
        $data['created_at'] = $this->created_at?->toIso8601String();
        $data['updated_at'] = $this->updated_at?->toIso8601String();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendStandardGradingFields(array $data): array
    {
        $data['actual_spread'] = $this->actual_spread;
        $data['actual_total'] = $this->actual_total;
        $data['spread_error'] = $this->spread_error;
        $data['total_error'] = $this->total_error;
        $data['winner_correct'] = $this->winner_correct;
        $data['graded_at'] = $this->graded_at?->toIso8601String();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendWinProbabilityFields(array $data, float $winProbability): array
    {
        $normalizedWinProbability = round($winProbability, 3);

        $data['win_probability'] = $normalizedWinProbability;
        $data['home_win_probability'] = $normalizedWinProbability;
        $data['away_win_probability'] = round(1 - $normalizedWinProbability, 3);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendLiveSpreadFields(array $data): array
    {
        $isLive = $this->isLivePrediction();

        $data['live_predicted_spread'] = $isLive && $this->live_predicted_spread !== null
            ? (float) $this->live_predicted_spread
            : null;
        $data['live_predicted_total'] = $isLive && $this->live_predicted_total !== null
            ? (float) $this->live_predicted_total
            : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendLiveWinProbabilityFields(array $data, string $remainingField = 'live_seconds_remaining'): array
    {
        $isLive = $this->isLivePrediction();

        $data['live_win_probability'] = $isLive && $this->live_win_probability !== null
            ? (float) $this->live_win_probability
            : null;
        $data[$remainingField] = $isLive ? $this->{$remainingField} : null;
        $data['live_updated_at'] = $isLive ? $this->live_updated_at?->toIso8601String() : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendNarrativeFields(array $data, Request $request, string $sport): array
    {
        $game = $this->relationLoaded('game') ? $this->game : null;
        $narrativeService = app(PredictionNarrativeService::class);
        $currentHash = $narrativeService->inputHashForSport($this->resource, $game, $sport);
        $storedNarrative = is_array($this->narrative_json ?? null) ? $this->narrative_json : null;
        $storedHash = (string) ($this->narrative_input_hash ?? '');

        $data['narrative'] = $storedNarrative && $storedHash !== '' && hash_equals($storedHash, $currentHash)
            ? $storedNarrative
            : $narrativeService->forSport($this->resource, $game, $sport, false);

        return $data;
    }
}
