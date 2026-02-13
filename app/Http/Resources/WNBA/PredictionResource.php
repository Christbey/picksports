<?php

namespace App\Http\Resources\WNBA;

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
            $data['predicted_spread'] = (float) $this->predicted_spread;
            $data['predicted_total'] = (float) $this->predicted_total;
        }

        // Win Probability
        if ($tier?->hasDataPermission('win_probability')) {
            $data['win_probability'] = (float) $this->win_probability;
        }

        // Confidence Score
        if ($tier?->hasDataPermission('confidence_score')) {
            $data['confidence_score'] = (float) $this->confidence_score;
        }

        // Away Elo
        if ($tier?->hasDataPermission('away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
            $data['away_off_eff'] = $this->away_off_eff ? (float) $this->away_off_eff : null;
            $data['away_def_eff'] = $this->away_def_eff ? (float) $this->away_def_eff : null;
        }

        // Home Elo
        if ($tier?->hasDataPermission('home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
            $data['home_off_eff'] = $this->home_off_eff ? (float) $this->home_off_eff : null;
            $data['home_def_eff'] = $this->home_def_eff ? (float) $this->home_def_eff : null;
        }

        $data['created_at'] = $this->created_at?->toIso8601String();
        $data['updated_at'] = $this->updated_at?->toIso8601String();

        return $data;
    }
}
