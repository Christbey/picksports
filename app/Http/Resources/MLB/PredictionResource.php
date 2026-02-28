<?php

namespace App\Http\Resources\MLB;

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
            $data['win_probability'] = (float) $this->win_probability;
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $data['confidence_score'] = (float) $this->confidence_score;
        }

        // Away Elo (includes team and pitcher elo)
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_team_elo'] = (float) $this->away_team_elo;
            $data['away_pitcher_elo'] = (float) $this->away_pitcher_elo;
            $data['away_combined_elo'] = (float) $this->away_combined_elo;
        }

        // Home Elo (includes team and pitcher elo)
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_team_elo'] = (float) $this->home_team_elo;
            $data['home_pitcher_elo'] = (float) $this->home_pitcher_elo;
            $data['home_combined_elo'] = (float) $this->home_combined_elo;
        }

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }
}
