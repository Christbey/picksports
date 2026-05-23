<?php

namespace App\Http\Resources\NFL;

use App\Actions\NFL\CalculateBettingValue;
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
            $bettingValue = app(CalculateBettingValue::class)->execute($this->game);
            $data['betting_value'] = $bettingValue;
            $data['betting_value_summary'] = $this->bettingValueSummary($bettingValue);
            $data['prediction_analysis'] = $this->predictionAnalysisSummary();
        }

        $data = $this->appendDepthChartContext($data);
        $data = $this->appendNarrativeFields($data, $request, 'nfl');

        return $this->appendStandardTimestamps($this->appendStandardGradingFields($data));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $bettingValue
     * @return array<string, mixed>
     */
    private function bettingValueSummary(?array $bettingValue): array
    {
        $plays = collect($bettingValue ?? [])
            ->filter(fn (array $recommendation) => ($recommendation['is_playable'] ?? false) === true)
            ->values();

        $best = $plays
            ->sortByDesc(fn (array $recommendation) => ($this->gradeRank((string) ($recommendation['grade'] ?? 'Pass')) * 1000) + (float) ($recommendation['edge'] ?? 0))
            ->first();

        return [
            'has_playable_value' => $plays->isNotEmpty(),
            'play_count' => $plays->count(),
            'best_grade' => $best['grade'] ?? null,
            'best_recommendation' => $best['recommendation'] ?? null,
            'best_type' => $best['type'] ?? null,
            'best_edge' => isset($best['edge']) ? (float) $best['edge'] : null,
            'best_units' => isset($best['bet_units']) ? (float) $best['bet_units'] : null,
            'risk_flags' => $plays
                ->flatMap(fn (array $recommendation) => (array) ($recommendation['risk_flags'] ?? []))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function predictionAnalysisSummary(): ?array
    {
        $metadata = is_array($this->model_metadata ?? null) ? $this->model_metadata : [];
        $analysis = $metadata['analysis_layer'] ?? null;

        if (! is_array($analysis) || ($analysis['applied'] ?? false) !== true) {
            return null;
        }

        return [
            'trust_score' => isset($analysis['trust_score']) ? (float) $analysis['trust_score'] : null,
            'bet_classification' => $analysis['bet_classification'] ?? null,
            'model_signal_classification' => $analysis['model_signal_classification'] ?? null,
            'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
            'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
            'player_position_grades' => $metadata['player_position_grades'] ?? null,
            'bet_rule_evaluation' => $analysis['bet_rule_evaluation'] ?? null,
            'validated_signals' => $analysis['validated_signals'] ?? [],
            'best_validated_signal' => $analysis['best_validated_signal'] ?? null,
            'calculated_edge' => $analysis['calculated_edge'] ?? null,
            'analysis_confidence' => $analysis['analysis_confidence'] ?? null,
        ];
    }

    private function gradeRank(string $grade): int
    {
        return match ($grade) {
            'A' => 3,
            'B' => 2,
            'C' => 1,
            default => 0,
        };
    }
}
