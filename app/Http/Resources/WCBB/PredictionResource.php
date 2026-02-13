<?php

namespace App\Http\Resources\WCBB;

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
        }

        // Win Probability
        if ($tier?->hasDataPermission('win_probability')) {
            $data['win_probability'] = $this->win_probability;
        }

        // Confidence Score
        if ($tier?->hasDataPermission('confidence_score')) {
            $data['confidence_score'] = $this->confidence_score;
        }

        // Away Elo
        if ($tier?->hasDataPermission('away_elo')) {
            $data['away_elo'] = $this->away_elo;
            $data['away_off_eff'] = $this->away_off_eff;
            $data['away_def_eff'] = $this->away_def_eff;
        }

        // Home Elo
        if ($tier?->hasDataPermission('home_elo')) {
            $data['home_elo'] = $this->home_elo;
            $data['home_off_eff'] = $this->home_off_eff;
            $data['home_def_eff'] = $this->home_def_eff;
        }

        // Grading fields (always included for historical analysis)
        $data['actual_spread'] = $this->actual_spread;
        $data['actual_total'] = $this->actual_total;
        $data['spread_error'] = $this->spread_error;
        $data['total_error'] = $this->total_error;
        $data['winner_correct'] = $this->winner_correct;
        $data['graded_at'] = $this->graded_at?->toIso8601String();

        $data['created_at'] = $this->created_at?->toIso8601String();
        $data['updated_at'] = $this->updated_at?->toIso8601String();

        return $data;
    }
}
