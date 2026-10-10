<?php

namespace App\Services\CFB;

class CfbRelativeVariabilityPenalty
{
    public const VERSION = 'relative-variability-penalty-v1';

    public function assess(float $mean, ?array $consistency, int $currentSeasonGames): array
    {
        $std = $consistency['std_dev'] ?? null;
        if ($mean <= 0 || ! is_numeric($std) || $std < 0 || ! is_finite($mean) || ! is_finite((float) $std)) {
            return ['policy' => self::VERSION, 'applied' => false, 'reason' => 'relative_variability_unavailable'];
        }
        $relative = (float) $std / $mean;
        $variability = (int) round(min(8, $relative * 8));
        $sample = max(0, 6 - max(0, $currentSeasonGames));

        return ['policy' => self::VERSION, 'applied' => true, 'relative_stddev' => round($relative, 4),
            'current_season_games' => $currentSeasonGames, 'variability_penalty' => $variability,
            'sample_penalty' => $sample, 'penalty' => min(14, $variability + $sample),
            'score_is_probability' => false, 'probability_status' => 'uncalibrated'];
    }
}
