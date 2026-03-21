<?php

namespace App\Http\Resources\NFL;

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
            $data = $this->appendLiveSpreadFields($data);
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $data = $this->appendWinProbabilityFields($data, (float) $this->win_probability);
            $data = $this->appendLiveWinProbabilityFields($data);
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $data['confidence_score'] = (float) $this->confidence_score;
        }

        // Away Elo
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = (float) $this->away_elo;
        }

        // Home Elo
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = (float) $this->home_elo;
        }

        // Betting Value
        if ($this->hasTierPermission($request, 'betting_value') && $this->game) {
            $data['betting_value'] = app(\App\Actions\NFL\CalculateBettingValue::class)->execute($this->game);
        }

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }
}
