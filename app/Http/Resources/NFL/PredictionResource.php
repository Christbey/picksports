<?php

namespace App\Http\Resources\NFL;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PredictionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $tier = $user?->subscriptionTier();

        $data = [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'game' => GameResource::make($this->whenLoaded('game')),
        ];

        // Spread (includes predicted_spread and predicted_total)
        if ($tier?->hasDataPermission('spread')) {
            $data['predicted_spread'] = $this->predicted_spread;
            $data['predicted_total'] = $this->predicted_total;
            $data['live_predicted_spread'] = $this->live_predicted_spread;
            $data['live_predicted_total'] = $this->live_predicted_total;
        }

        // Win Probability
        if ($tier?->hasDataPermission('win_probability')) {
            $data['win_probability'] = $this->win_probability;
            $data['live_win_probability'] = $this->live_win_probability;
            $data['live_seconds_remaining'] = $this->live_seconds_remaining;
            $data['live_updated_at'] = $this->live_updated_at?->toIso8601String();
        }

        // Confidence Score
        if ($tier?->hasDataPermission('confidence_score')) {
            $data['confidence_score'] = $this->confidence_score;
        }

        // Away Elo
        if ($tier?->hasDataPermission('away_elo')) {
            $data['away_elo'] = $this->away_elo;
        }

        // Home Elo
        if ($tier?->hasDataPermission('home_elo')) {
            $data['home_elo'] = $this->home_elo;
        }

        // Elo Diff (calculated field)
        if ($tier?->hasDataPermission('elo_diff')) {
            $data['elo_diff'] = $this->home_elo - $this->away_elo;
        }

        // Betting Value
        if ($tier?->hasDataPermission('betting_value') && $this->game) {
            $data['betting_value'] = app(\App\Actions\NFL\CalculateBettingValue::class)->execute($this->game);
        }

        $data['created_at'] = $this->created_at?->toIso8601String();
        $data['updated_at'] = $this->updated_at?->toIso8601String();

        return $data;
    }
}
