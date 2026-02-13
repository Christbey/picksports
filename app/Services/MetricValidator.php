<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Validates team metrics are within expected ranges.
 *
 * This service checks calculated metrics against sport-specific thresholds
 * and logs warnings when values fall outside expected ranges, helping identify
 * potential data quality issues or calculation errors.
 */
class MetricValidator
{
    private const THRESHOLDS = [
        'nfl' => [
            'offensive_rating' => ['min' => 10, 'max' => 40],
            'defensive_rating' => ['min' => 10, 'max' => 40],
            'net_rating' => ['min' => -20, 'max' => 20],
            'yards_per_game' => ['min' => 200, 'max' => 500],
            'turnover_differential' => ['min' => -3, 'max' => 3],
        ],
        'nba' => [
            'offensive_efficiency' => ['min' => 90, 'max' => 130],
            'defensive_efficiency' => ['min' => 90, 'max' => 130],
            'tempo' => ['min' => 90, 'max' => 110],
        ],
        'cbb' => [
            'offensive_efficiency' => ['min' => 80, 'max' => 130],
            'defensive_efficiency' => ['min' => 80, 'max' => 130],
            'tempo' => ['min' => 60, 'max' => 85],
        ],
        'wcbb' => [
            'offensive_efficiency' => ['min' => 70, 'max' => 120],
            'defensive_efficiency' => ['min' => 70, 'max' => 120],
            'tempo' => ['min' => 55, 'max' => 80],
        ],
        'mlb' => [
            'offensive_rating' => ['min' => 0, 'max' => 200],
            'pitching_rating' => ['min' => 0, 'max' => 150],
            'team_era' => ['min' => 2.0, 'max' => 7.0],
            'batting_average' => ['min' => 0.200, 'max' => 0.350],
        ],
    ];

    /**
     * Validate metrics are within expected ranges.
     *
     * @param  array  $metrics  Key-value pairs of metric names and values
     * @param  string  $sport  Sport abbreviation
     * @param  array  $context  Additional context for logging
     */
    public function validate(array $metrics, string $sport, array $context = []): void
    {
        $sport = strtolower($sport);

        if (! isset(self::THRESHOLDS[$sport])) {
            Log::warning('No validation thresholds defined for sport', [
                'sport' => $sport,
            ]);

            return;
        }

        foreach ($metrics as $key => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }

            if (isset(self::THRESHOLDS[$sport][$key])) {
                $threshold = self::THRESHOLDS[$sport][$key];

                if ($value < $threshold['min'] || $value > $threshold['max']) {
                    Log::warning('Metric out of expected range', array_merge([
                        'sport' => $sport,
                        'metric' => $key,
                        'value' => $value,
                        'expected_min' => $threshold['min'],
                        'expected_max' => $threshold['max'],
                    ], $context));
                }
            }
        }
    }

    /**
     * Get thresholds for a specific sport.
     */
    public static function getThresholds(string $sport): array
    {
        return self::THRESHOLDS[strtolower($sport)] ?? [];
    }
}
