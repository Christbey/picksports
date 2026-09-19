<?php

namespace App\Services\CFB\Predictions;

class CfbPredictionInputQuality
{
    /** @param array<string, mixed> $inputs @return array<string, mixed> */
    public static function assess(array $inputs): array
    {
        $flags = [];
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

        return ['qualified' => $flags === [], 'risk_flags' => $flags, 'sample_games' => $samples,
            'probability_status' => 'uncalibrated_model_estimate'];
    }
}
