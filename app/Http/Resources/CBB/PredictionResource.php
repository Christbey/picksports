<?php

namespace App\Http\Resources\CBB;

use App\Actions\CBB\CalculateBettingValue;
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
            $winProbability = (float) $this->win_probability;
            $data['win_probability'] = $winProbability;
            $data['home_win_probability'] = $winProbability;
            $data['away_win_probability'] = 1 - $winProbability;
        }

        // Confidence Score
        if ($tier?->hasDataPermission('confidence_score')) {
            $confidenceScore = (float) $this->confidence_score;
            $data['confidence_score'] = $confidenceScore;

            // Determine confidence level based on score
            $data['confidence_level'] = match (true) {
                $confidenceScore >= 80 => 'high',
                $confidenceScore >= 50 => 'medium',
                default => 'low',
            };
        }

        // Away Elo
        if ($tier?->hasDataPermission('away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
            $data['away_off_eff'] = (float) $this->away_off_eff;
            $data['away_def_eff'] = (float) $this->away_def_eff;
        }

        // Home Elo
        if ($tier?->hasDataPermission('home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
            $data['home_off_eff'] = (float) $this->home_off_eff;
            $data['home_def_eff'] = (float) $this->home_def_eff;
        }

        // Betting Value
        if ($tier?->hasDataPermission('betting_value') && $this->relationLoaded('game')) {
            $data['betting_value'] = $this->betting_value ?? app(CalculateBettingValue::class)->execute($this->game);
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
