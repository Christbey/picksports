<?php

namespace App\Services\PlayerStats;

class NflPlayerEpaCalculator
{
    public const PROFILE_NFL = 'nfl';

    /**
     * @var array<string,float>
     */
    private const DEFAULT_WEIGHTS = [
        'passing_yards' => 0.045,
        'passing_touchdowns' => 3.50,
        'interceptions_thrown' => -4.00,
        'sacks_taken' => -1.20,
        'sack_yards_lost' => -0.03,
        'passing_two_point_conversions' => 1.80,
        'rushing_yards' => 0.08,
        'rushing_touchdowns' => 5.00,
        'rushing_attempts' => -0.04,
        'rushing_two_point_conversions' => 1.80,
        'receptions' => 0.30,
        'receiving_yards' => 0.09,
        'receiving_touchdowns' => 5.00,
        'receiving_targets' => -0.02,
        'receiving_two_point_conversions' => 1.80,
        'kickoff_return_yards' => 0.03,
        'kickoff_return_touchdowns' => 5.50,
        'punt_return_yards' => 0.04,
        'punt_return_touchdowns' => 5.50,
        'tackles_total' => 0.12,
        'sacks' => 1.70,
        'interceptions' => 3.20,
        'passes_defended' => 0.45,
        'fumbles_recovered' => 2.20,
        'field_goals_made' => 2.60,
        'field_goals_attempted' => -0.20,
        'extra_points_made' => 1.00,
        'extra_points_attempted' => -0.05,
    ];

    /**
     * @param  array<string,float|int|null>  $stats
     */
    public function estimateFromBoxScore(array $stats): float
    {
        $weights = $this->profileWeights();
        $epa = 0.0;

        foreach ($weights as $key => $weight) {
            $epa += $weight * (float) ($stats[$key] ?? 0);
        }

        return round($epa, 2);
    }

    public function estimatePerOpportunity(float $estimatedEpa, float|int|null $opportunities): float
    {
        $opps = (float) ($opportunities ?? 0);
        if ($opps <= 0) {
            return 0.0;
        }

        return round($estimatedEpa / $opps, 3);
    }

    /**
     * @return array<string,float>
     */
    private function profileWeights(): array
    {
        try {
            $configured = config('epa.profiles.nfl', []);
        } catch (\Throwable) {
            return self::DEFAULT_WEIGHTS;
        }

        if (! is_array($configured)) {
            return self::DEFAULT_WEIGHTS;
        }

        return array_merge(self::DEFAULT_WEIGHTS, $configured);
    }
}
