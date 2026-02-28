<?php

namespace App\Http\Resources\WCBB;

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
            $data['predicted_spread'] = $this->predicted_spread;
            $data['predicted_total'] = $this->predicted_total;
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $data['win_probability'] = $this->win_probability;
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $data['confidence_score'] = $this->confidence_score;
        }

        // Away Elo
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = $this->away_elo;
            $data['away_off_eff'] = $this->away_off_eff;
            $data['away_def_eff'] = $this->away_def_eff;
        }

        // Home Elo
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = $this->home_elo;
            $data['home_off_eff'] = $this->home_off_eff;
            $data['home_def_eff'] = $this->home_def_eff;
        }

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }
}
