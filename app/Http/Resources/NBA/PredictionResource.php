<?php

namespace App\Http\Resources\NBA;

use App\Actions\NBA\CalculateBettingValue;
use App\Http\Resources\Sports\AbstractPredictionResource;
use App\Models\NBA\Game;
use App\Services\Predictions\PredictionNarrativeService;
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
            $data['injury_spread_adj'] = (float) ($this->injury_spread_adj ?? 0);
            $data['injury_total_adj'] = (float) ($this->injury_total_adj ?? 0);
            $data['home_injuries_out'] = (int) ($this->home_injuries_out ?? 0);
            $data['away_injuries_out'] = (int) ($this->away_injuries_out ?? 0);
            $data['home_injuries_questionable'] = (int) ($this->home_injuries_questionable ?? 0);
            $data['away_injuries_questionable'] = (int) ($this->away_injuries_questionable ?? 0);
        }

        // Win Probability
        if ($this->hasTierPermission($request, 'win_probability')) {
            $winProbability = (float) $this->win_probability;
            $data = $this->appendWinProbabilityFields($data, $winProbability);
            $data = $this->appendLiveWinProbabilityFields($data);
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

        // Template-backed narrative summary (safe fallback; can be swapped for LLM later).
        if (
            $this->hasTierPermission($request, 'spread')
            && $this->hasTierPermission($request, 'win_probability')
            && $this->hasTierPermission($request, 'confidence_score')
        ) {
            /** @var Game|null $game */
            $game = $this->relationLoaded('game') ? $this->game : null;
            $narrativeService = app(PredictionNarrativeService::class);
            $currentHash = $narrativeService->inputHashForNba($this->resource, $game);
            $storedNarrative = is_array($this->narrative_json) ? $this->narrative_json : null;
            $storedHash = (string) ($this->narrative_input_hash ?? '');

            if ($storedNarrative && $storedHash !== '' && hash_equals($storedHash, $currentHash)) {
                $data['narrative'] = $storedNarrative;
            } else {
                // Request-path fallback remains deterministic and fast.
                $data['narrative'] = $narrativeService->forNba($this->resource, $game, false);
            }
        }

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }
}
