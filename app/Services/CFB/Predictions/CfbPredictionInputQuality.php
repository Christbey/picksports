<?php

namespace App\Services\CFB\Predictions;

use App\Services\CFB\Ratings\ResultRatingModel;

class CfbPredictionInputQuality
{
    /** @param array<string, mixed> $inputs @return array<string, mixed> */
    public static function assess(array $inputs): array
    {
        $flags = data_get($inputs, 'scoring_challenger.promoted', false) ? ['challenger_market_validation_required'] : [];
        $samples = [];
        foreach (['home', 'away'] as $side) {
            if (data_get($inputs, 'require_versioned_elo', false)
                && ! data_get($inputs, $side.'.elo_evidence.qualified', false)) {
                $flags[] = $side.'_unverified_elo_provenance';
            }
            $metrics = (array) data_get($inputs, $side.'.metrics', []);
            $samples[$side] = max(0, (int) ($metrics['wins'] ?? 0) + (int) ($metrics['losses'] ?? 0));
            if ($samples[$side] === 0 || ! is_numeric($metrics['points_per_game'] ?? null)
                || ! is_numeric($metrics['points_allowed_per_game'] ?? null)) {
                $flags[] = $side.'_missing_team_metrics';
            }
            if (data_get($inputs, $side.'.availability.quarterback_hold', false)) {
                $flags[] = $side.'_quarterback_unresolved';
            }
        }

        $independent = $inputs['independent_result_rating'] ?? null;
        if (ResultRatingModel::usableEvidence($independent)) {
            $original = $flags;
            $flags = array_values(array_filter($flags, fn ($flag) => ! str_ends_with($flag, 'missing_team_metrics') && ! str_ends_with($flag, 'unverified_elo_provenance')));

            return ['qualified' => $flags === [], 'risk_flags' => $flags, 'replaced_input_flags' => $original,
                'evidence_path' => 'independent_completed_results',
                'sample_games' => ['home' => $independent['home']['current_games'], 'away' => $independent['away']['current_games']],
                'prior_games' => ['home' => $independent['home']['prior_games'], 'away' => $independent['away']['prior_games']],
                'probability_status' => 'uncalibrated_model_estimate'];
        }

        return ['qualified' => $flags === [], 'risk_flags' => $flags, 'sample_games' => $samples,
            'probability_status' => 'uncalibrated_model_estimate'];
    }
}
