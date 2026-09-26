<?php

namespace App\Services\NFL;

/** Directional injury heuristic; coefficients are not calibrated win probabilities. */
final class NflInjuryTotalAdjustment
{
    public function unit(?string $position): string
    {
        return match (strtoupper(trim((string) $position))) {
            'QB', 'RB', 'HB', 'FB', 'WR', 'TE', 'OL', 'OT', 'OG', 'T', 'G', 'C', 'LT', 'RT', 'LG', 'RG' => 'offense',
            'DL', 'DE', 'DT', 'NT', 'EDGE', 'LB', 'ILB', 'OLB', 'MLB', 'DB', 'CB', 'S', 'FS', 'SS' => 'defense',
            default => 'unknown',
        };
    }

    public function calculate(array $home, array $away): array
    {
        $penalties = [];
        foreach (['offense', 'defense'] as $unit) {
            $penalties[$unit] = 0.0;
            foreach (['out' => 'injury_out_total_penalty', 'questionable' => 'injury_questionable_total_penalty'] as $bucket => $key) {
                $count = (float) data_get($home, "units.$unit.$bucket", 0)
                    + (float) data_get($away, "units.$unit.$bucket", 0);
                $penalties[$unit] += $count * max(0.0, (float) config("nfl.predictions.$key", 0));
            }
        }
        $raw = $penalties['defense'] - $penalties['offense'];
        $cap = max(0.0, (float) config('nfl.predictions.depth_chart_injuries.max_total_adjustment', 3.0));

        return ['version' => 'unit-aware-v1', 'offense_reduction' => $penalties['offense'],
            'defense_increase' => $penalties['defense'], 'raw_adjustment' => $raw,
            'adjustment' => max(-$cap, min($cap, $raw)), 'cap' => $cap,
            'unknown_position_policy' => 'excluded_from_total', 'calibrated' => false];
    }
}
