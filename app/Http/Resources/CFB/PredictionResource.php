<?php

namespace App\Http\Resources\CFB;

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
            $data['predicted_total'] = $this->predicted_total ? (float) $this->predicted_total : null;
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $data['win_probability'] = (float) $this->win_probability;
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $data['confidence_score'] = (float) $this->confidence_score;
        }

        // Away Elo (includes FPI if available)
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
            $data['away_fpi'] = $this->away_fpi ? (float) $this->away_fpi : null;
        }

        // Home Elo (includes FPI if available)
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
            $data['home_fpi'] = $this->home_fpi ? (float) $this->home_fpi : null;
        }

        return $this->appendStandardTimestamps($data);
    }
}
