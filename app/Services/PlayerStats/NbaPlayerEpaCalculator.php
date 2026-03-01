<?php

namespace App\Services\PlayerStats;

class NbaPlayerEpaCalculator
{
    public const PROFILE_NBA = 'nba';

    public const PROFILE_CBB = 'cbb';

    /**
     * @var array<string, array<string, float>>
     */
    private const DEFAULT_PROFILE_WEIGHTS = [
        self::PROFILE_NBA => [
            'points' => 0.70,
            'assists' => 0.25,
            'rebounds_total' => 0.10,
            'steals' => 0.30,
            'blocks' => 0.30,
            'turnovers' => -0.30,
            'missed_field_goals' => -0.08,
            'missed_free_throws' => -0.05,
        ],
        self::PROFILE_CBB => [
            'points' => 0.62,
            'assists' => 0.25,
            'rebounds_total' => 0.14,
            'steals' => 0.30,
            'blocks' => 0.30,
            'turnovers' => -0.36,
            'missed_field_goals' => -0.10,
            'missed_free_throws' => -0.05,
        ],
    ];

    /**
     * Lightweight possession-value proxy from box score stats.
     *
     * This is not true play-by-play EPA; it is a deterministic estimate that can
     * be computed from currently stored NBA player stat fields.
     */
    public function estimateFromBoxScore(
        float|int|null $points,
        float|int|null $assists,
        float|int|null $reboundsTotal,
        float|int|null $steals,
        float|int|null $blocks,
        float|int|null $turnovers,
        float|int|null $fieldGoalsMade,
        float|int|null $fieldGoalsAttempted,
        float|int|null $freeThrowsMade,
        float|int|null $freeThrowsAttempted,
        string $profile = self::PROFILE_NBA
    ): float {
        $points = (float) ($points ?? 0);
        $assists = (float) ($assists ?? 0);
        $reboundsTotal = (float) ($reboundsTotal ?? 0);
        $steals = (float) ($steals ?? 0);
        $blocks = (float) ($blocks ?? 0);
        $turnovers = (float) ($turnovers ?? 0);
        $fieldGoalsMade = (float) ($fieldGoalsMade ?? 0);
        $fieldGoalsAttempted = (float) ($fieldGoalsAttempted ?? 0);
        $freeThrowsMade = (float) ($freeThrowsMade ?? 0);
        $freeThrowsAttempted = (float) ($freeThrowsAttempted ?? 0);

        $missedFieldGoals = max(0.0, $fieldGoalsAttempted - $fieldGoalsMade);
        $missedFreeThrows = max(0.0, $freeThrowsAttempted - $freeThrowsMade);
        $weights = $this->profileWeights($profile);

        $estimatedEpa = $weights['points'] * $points
            + $weights['assists'] * $assists
            + $weights['rebounds_total'] * $reboundsTotal
            + $weights['steals'] * $steals
            + $weights['blocks'] * $blocks
            + $weights['turnovers'] * $turnovers
            + $weights['missed_field_goals'] * $missedFieldGoals
            + $weights['missed_free_throws'] * $missedFreeThrows;

        return round($estimatedEpa, 2);
    }

    public function estimatePer36(float $estimatedEpa, string|float|int|null $minutesPlayed): float
    {
        $minutes = $this->minutesToDecimal($minutesPlayed);

        if ($minutes <= 0) {
            return 0.0;
        }

        return round(($estimatedEpa / $minutes) * 36, 2);
    }

    public function minutesToDecimal(string|float|int|null $minutesPlayed): float
    {
        if ($minutesPlayed === null || $minutesPlayed === '') {
            return 0.0;
        }

        if (is_numeric($minutesPlayed)) {
            return (float) $minutesPlayed;
        }

        $value = trim((string) $minutesPlayed);

        if (! str_contains($value, ':')) {
            return is_numeric($value) ? (float) $value : 0.0;
        }

        [$mins, $secs] = array_pad(explode(':', $value, 2), 2, '0');

        $minutes = (float) (is_numeric($mins) ? $mins : 0);
        $seconds = (float) (is_numeric($secs) ? $secs : 0);

        if ($seconds < 0) {
            $seconds = 0;
        }

        return $minutes + ($seconds / 60);
    }

    /**
     * @return array<string, float>
     */
    private function profileWeights(string $profile): array
    {
        $defaults = self::DEFAULT_PROFILE_WEIGHTS[self::PROFILE_NBA];
        $defaultProfile = self::DEFAULT_PROFILE_WEIGHTS[$profile] ?? $defaults;
        $configuredProfile = [];

        try {
            $configuredProfile = config("epa.profiles.{$profile}", []);
        } catch (\Throwable) {
            return $defaultProfile;
        }

        if (! is_array($configuredProfile)) {
            return $defaultProfile;
        }

        return array_merge($defaultProfile, $configuredProfile);
    }
}
