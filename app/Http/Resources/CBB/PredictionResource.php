<?php

namespace App\Http\Resources\CBB;

use App\Actions\CBB\CalculateBettingValue;
use App\Http\Resources\Sports\AbstractPredictionResource;
use Illuminate\Http\Request;

class PredictionResource extends AbstractPredictionResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->basePredictionData(GameResource::class);

        // Spread (includes predicted_spread and predicted_total)
        if ($this->hasTierPermission($request, 'spread')) {
            $data['predicted_spread'] = (float) $this->predicted_spread;
            $data['predicted_total'] = (float) $this->predicted_total;
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $winProbability = (float) $this->win_probability;
            $data['win_probability'] = $winProbability;
            $data['home_win_probability'] = $winProbability;
            $data['away_win_probability'] = 1 - $winProbability;
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $confidenceScore = (float) $this->confidence_score;
            $data['confidence_score'] = $confidenceScore;

            // Determine confidence level based on score
            $data['confidence_level'] = match (true) {
                $confidenceScore >= 75 => 'high',
                $confidenceScore >= 60 => 'medium',
                default => 'low',
            };
        }

        // Away Elo
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
            $data['away_off_eff'] = (float) $this->away_off_eff;
            $data['away_def_eff'] = (float) $this->away_def_eff;
        }

        // Home Elo
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
            $data['home_off_eff'] = (float) $this->home_off_eff;
            $data['home_def_eff'] = (float) $this->home_def_eff;
        }

        // Betting Value
        if ($this->hasTierPermission($request, 'betting_value') && $this->relationLoaded('game')) {
            $data['betting_value'] = $this->betting_value ?? app(CalculateBettingValue::class)->execute($this->game);
        }

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }
}
