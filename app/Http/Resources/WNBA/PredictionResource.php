<?php

namespace App\Http\Resources\WNBA;

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

        // Away Elo
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
            $data['away_off_eff'] = $this->away_off_eff ? (float) $this->away_off_eff : null;
            $data['away_def_eff'] = $this->away_def_eff ? (float) $this->away_def_eff : null;
        }

        // Home Elo
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
            $data['home_off_eff'] = $this->home_off_eff ? (float) $this->home_off_eff : null;
            $data['home_def_eff'] = $this->home_def_eff ? (float) $this->home_def_eff : null;
        }

        return $this->appendStandardTimestamps($data);
    }
}
