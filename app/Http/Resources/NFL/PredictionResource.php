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
            $data['predicted_spread'] = $this->predicted_spread;
            $data['predicted_total'] = $this->predicted_total;
            $data['live_predicted_spread'] = $this->live_predicted_spread;
            $data['live_predicted_total'] = $this->live_predicted_total;
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $data['win_probability'] = $this->win_probability;
            $data['live_win_probability'] = $this->live_win_probability;
            $data['live_seconds_remaining'] = $this->live_seconds_remaining;
            $data['live_updated_at'] = $this->live_updated_at?->toIso8601String();
        }

        // Confidence Score
        if ($this->hasTierPermission($request, 'confidence_score')) {
            $data['confidence_score'] = $this->confidence_score;
        }

        // Away Elo
        if ($this->hasTierPermission($request, 'away_elo')) {
            $data['away_elo'] = $this->away_elo;
        }

        // Home Elo
        if ($this->hasTierPermission($request, 'home_elo')) {
            $data['home_elo'] = $this->home_elo;
        }

        // Elo Diff (calculated field)
        if ($this->hasTierPermission($request, 'elo_diff')) {
            $data['elo_diff'] = $this->home_elo - $this->away_elo;
        }

        // Betting Value
        if ($this->hasTierPermission($request, 'betting_value') && $this->game) {
            $data['betting_value'] = app(\App\Actions\NFL\CalculateBettingValue::class)->execute($this->game);
        }

        return $this->appendStandardTimestamps($data);
    }
}
