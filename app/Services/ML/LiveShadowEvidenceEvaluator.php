<?php

namespace App\Services\ML;

use App\Models\BetDecision;
use App\Models\ModelArtifact;
use App\Models\ShadowModelOutput;

class LiveShadowEvidenceEvaluator
{
    /**
     * @param  list<string>  $markets
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function evaluate(ModelArtifact $artifact, array $markets, array $criteria = []): array
    {
        $minimumObservations = max(0, (int) ($criteria['minimum_live_shadow_observations']
            ?? config('ml.promotion.live_shadow.minimum_observations', 25)));
        $minimumSettled = max(0, (int) ($criteria['minimum_settled_shadow_decisions']
            ?? config('ml.promotion.live_shadow.minimum_settled_decisions', 10)));
        $marketEvidence = [];

        foreach ($markets as $market) {
            $normalizedMarket = ModelArtifact::normalizeMarketType($market);
            $observations = ShadowModelOutput::query()
                ->where('model_artifact_id', $artifact->id)
                ->where('market_type', $normalizedMarket)
                ->whereHas('featureSnapshot', fn ($query) => $query
                    ->where('pregame_safe', true)
                    ->where('availability_status', 'observed_pregame')
                    ->whereColumn('features_available_at', '<=', 'game_start_at'))
                ->distinct()
                ->count('game_id');
            $settledDecisions = BetDecision::query()
                ->where('model_artifact_id', $artifact->id)
                ->whereNotNull('shadow_model_output_id')
                ->where('pregame_safe', true)
                ->whereIn('market_type', $this->decisionMarketTypes($normalizedMarket))
                ->whereHas('featureSnapshot', fn ($query) => $query
                    ->where('pregame_safe', true)
                    ->where('availability_status', 'observed_pregame')
                    ->whereColumn('features_available_at', '<=', 'game_start_at'))
                ->whereHas('settlement')
                ->distinct()
                ->count('game_id');

            $checks = [
                'minimum_live_observations' => $observations >= $minimumObservations,
                'minimum_settled_decisions' => $settledDecisions >= $minimumSettled,
            ];
            $marketEvidence[$normalizedMarket] = [
                'passed' => ! in_array(false, $checks, true),
                'checks' => $checks,
                'live_pregame_safe_observations' => $observations,
                'settled_pregame_safe_decisions' => $settledDecisions,
                'minimum_live_pregame_safe_observations' => $minimumObservations,
                'minimum_settled_pregame_safe_decisions' => $minimumSettled,
            ];
        }

        return [
            'passed' => ! collect($marketEvidence)->contains(
                fn (array $evidence): bool => ! $evidence['passed'],
            ),
            'markets' => $marketEvidence,
            'criteria' => [
                'minimum_live_shadow_observations' => $minimumObservations,
                'minimum_settled_shadow_decisions' => $minimumSettled,
                'availability_status' => 'observed_pregame',
                'requires_pregame_safe_features' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function decisionMarketTypes(string $market): array
    {
        return match ($market) {
            'win_probability' => ['win_probability', 'moneyline', 'winner', 'h2h'],
            'spread' => ['spread', 'home_margin', 'margin'],
            'total' => ['total', 'totals', 'total_points', 'over_under'],
            default => [$market],
        };
    }
}
