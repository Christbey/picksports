<?php

namespace App\Services\BettingRecommendations;

use Illuminate\Support\Collection;

/** Recent opportunity volume multiplied by efficiency from a longer eligible window. */
class NflPropOpportunityProjection
{
    public function project(Collection $history, string $market): ?array
    {
        return $this->calculate($history, $market, 0.0);
    }

    /** Offline candidate only: stabilize recent volume using three effective games of longer-window volume. */
    public function projectStabilized(Collection $history, string $market): ?array
    {
        return $this->calculate($history, $market, 3.0);
    }

    private function calculate(Collection $history, string $market, float $volumePriorGames): ?array
    {
        [$volume, $output] = match ($market) {
            'player_pass_yds' => ['passing_attempts', 'passing_yards'],
            'player_pass_completions' => ['passing_attempts', 'passing_completions'],
            'player_pass_tds' => ['passing_attempts', 'passing_touchdowns'],
            'player_pass_interceptions' => ['passing_attempts', 'interceptions_thrown'],
            'player_pass_attempts' => ['passing_attempts', 'passing_attempts'],
            'player_rush_yds' => ['rushing_attempts', 'rushing_yards'],
            'player_rush_attempts' => ['rushing_attempts', 'rushing_attempts'],
            'player_receptions' => ['receiving_targets', 'receptions'],
            'player_reception_yds' => ['receiving_targets', 'receiving_yards'],
            default => [null, null],
        };
        if ($volume === null) {
            return null;
        }

        $window = $history->take(16)->values();
        $usable = $window->filter(fn ($row) => is_numeric($row->{$volume}) && is_numeric($row->{$output})
            && (float) $row->{$volume} >= 0);
        if ($usable->isEmpty()) {
            return null;
        }

        $weight = $opportunities = $production = $volumeWeight = $recentVolume = 0.0;
        foreach ($usable as $index => $row) {
            // Preserve chronological gaps when an opportunity field is missing.
            $w = pow(0.86, $index);
            $weight += $w;
            $opportunities += $w * (float) $row->{$volume};
            $production += $w * (float) $row->{$output};
            if ($index < 5) {
                $volumeWeight += $w;
                $recentVolume += $w * (float) $row->{$volume};
            }
        }
        $expected = $volumeWeight > 0 ? $recentVolume / $volumeWeight : $opportunities / $weight;
        if ($volumePriorGames > 0 && $volumeWeight > 0) {
            $expected = ($recentVolume + $volumePriorGames * ($opportunities / $weight)) / ($volumeWeight + $volumePriorGames);
        }
        $efficiency = $opportunities > 0 ? $production / $opportunities : 0.0;

        $projection = [
            'method' => 'recency_weighted_opportunities',
            'opportunity_field' => $volume,
            'production_field' => $output,
            'games' => $usable->count(),
            'volume_games' => $usable->filter(fn ($row, $index) => $index < 5)->count(),
            'missing_opportunity_games' => $window->count() - $usable->count(),
            'game_ids' => $usable->pluck('game_id')->all(),
            'decay' => 0.86,
            'expected_opportunities' => round($expected, 6),
            'production_per_opportunity' => round($efficiency, 6),
            'baseline_mean' => $expected * $efficiency,
        ];
        if ($volumePriorGames > 0) {
            $projection['method'] = 'stabilized_recency_weighted_opportunities';
            $projection['volume_prior_games'] = $volumePriorGames;
            $projection['status'] = 'experimental_not_validated';
        }

        return $projection;
    }
}
