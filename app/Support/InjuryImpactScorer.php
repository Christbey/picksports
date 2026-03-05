<?php

namespace App\Support;

use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InjuryImpactScorer
{
    /**
     * @var array<string, array{score: float, label: string, spread_impact: float, total_impact: float, multiplier: float}>
     */
    private array $cache = [];

    /**
     * @var array<string, bool>
     */
    private array $columnExistsCache = [];

    /**
     * Calibrated to mirror prediction logic:
     * - Same status bucket mapping
     * - Same per-sport injury penalty configuration
     * - Same EPA multiplier behavior where enabled
     *
     * @return array{score: float, label: string, spread_impact: float, total_impact: float, multiplier: float}
     */
    public function describe(string $sport, int $playerId, ?string $status): array
    {
        $key = "{$sport}:{$playerId}:".strtolower((string) $status);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $bucket = $this->injuryStatusBucket((string) ($status ?? ''));
        if ($bucket === null) {
            return $this->cache[$key] = [
                'score' => 0.0,
                'label' => 'Low',
                'spread_impact' => 0.0,
                'total_impact' => 0.0,
                'multiplier' => 1.0,
            ];
        }

        $multiplier = $this->injuryImpactMultiplier($sport, $playerId);
        $spreadPenalty = $bucket === 'out'
            ? $this->injuryPenaltyConfig($sport, 'injury_out_spread_penalty', $this->defaultOutSpreadPenalty($sport))
            : $this->injuryPenaltyConfig($sport, 'injury_questionable_spread_penalty', $this->defaultQuestionableSpreadPenalty($sport));
        $totalPenalty = $bucket === 'out'
            ? $this->injuryPenaltyConfig($sport, 'injury_out_total_penalty', $this->defaultOutTotalPenalty($sport))
            : $this->injuryPenaltyConfig($sport, 'injury_questionable_total_penalty', $this->defaultQuestionableTotalPenalty($sport));

        $spreadImpact = round($spreadPenalty * $multiplier, 2);
        $totalImpact = round($totalPenalty * $multiplier, 2);

        // Keep score tied to the same magnitude used in spread/total model adjustments.
        $combined = $spreadImpact + ($totalImpact * 0.75);
        $score = round(max(0.0, min(100.0, $combined * 50.0)), 1);
        $label = $this->labelForScore($score);

        return $this->cache[$key] = [
            'score' => $score,
            'label' => $label,
            'spread_impact' => $spreadImpact,
            'total_impact' => $totalImpact,
            'multiplier' => round($multiplier, 2),
        ];
    }

    private function labelForScore(float $score): string
    {
        if ($score >= 65) {
            return 'High';
        }
        if ($score >= 35) {
            return 'Medium';
        }

        return 'Low';
    }

    private function injuryPenaltyConfig(string $sport, string $key, float $default): float
    {
        $predictionConfig = config("{$sport}.prediction.{$key}");
        if (is_numeric($predictionConfig)) {
            return (float) $predictionConfig;
        }

        $predictionsConfig = config("{$sport}.predictions.{$key}");
        if (is_numeric($predictionsConfig)) {
            return (float) $predictionsConfig;
        }

        return $default;
    }

    private function defaultOutSpreadPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.50,
            'mlb' => 0.30,
            default => 0.75,
        };
    }

    private function defaultQuestionableSpreadPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.20,
            'mlb' => 0.10,
            default => 0.30,
        };
    }

    private function defaultOutTotalPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.30,
            'mlb' => 0.15,
            default => 0.40,
        };
    }

    private function defaultQuestionableTotalPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.10,
            'mlb' => 0.05,
            default => 0.15,
        };
    }

    private function injuryStatusBucket(string $status): ?string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'out')
            || str_contains($normalized, 'doubtful')
            || str_contains($normalized, 'inactive')
            || str_contains($normalized, 'suspended')
            || str_contains($normalized, 'ir')
        ) {
            return 'out';
        }

        if (
            str_contains($normalized, 'questionable')
            || str_contains($normalized, 'game-time')
            || str_contains($normalized, 'gtd')
            || str_contains($normalized, 'probable')
            || str_contains($normalized, 'day-to-day')
        ) {
            return 'questionable';
        }

        return null;
    }

    private function injuryImpactMultiplier(string $sport, int $playerId): float
    {
        if ($playerId <= 0 || ! $this->supportsEpaWeightedInjuryImpact($sport)) {
            return 1.0;
        }

        $statTable = "{$sport}_player_stats";
        if (! Schema::hasTable($statTable)) {
            return 1.0;
        }

        $lookback = (int) (config("{$sport}.prediction.injury_epa_lookback_games") ?? 10);
        $lookback = max(3, min(30, $lookback));

        $rows = DB::table($statTable)
            ->where('player_id', $playerId)
            ->orderByDesc('game_id')
            ->limit($lookback)
            ->get([
                'points',
                'assists',
                DB::raw($this->coalescedNumericExpr($statTable, ['rebounds_total', 'rebounds'], 'rebounds_total')),
                'steals',
                'blocks',
                'turnovers',
                'field_goals_made',
                'field_goals_attempted',
                'free_throws_made',
                'free_throws_attempted',
            ]);

        if ($rows->isEmpty()) {
            return 1.0;
        }

        $calculator = app(NbaPlayerEpaCalculator::class);
        $profile = $this->epaProfileForSport($sport);
        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += $calculator->estimateFromBoxScore(
                $row->points,
                $row->assists,
                $row->rebounds_total,
                $row->steals,
                $row->blocks,
                $row->turnovers,
                $row->field_goals_made,
                $row->field_goals_attempted,
                $row->free_throws_made,
                $row->free_throws_attempted,
                $profile
            );
        }

        $avgEpa = $sum / max(1, $rows->count());
        $baseline = (float) (config("{$sport}.prediction.injury_epa_baseline") ?? 12.0);
        $baseline = max(1.0, $baseline);
        $multiplier = $avgEpa / $baseline;

        $min = (float) (config("{$sport}.prediction.injury_epa_min_multiplier") ?? 0.5);
        $max = (float) (config("{$sport}.prediction.injury_epa_max_multiplier") ?? 2.0);
        $fallback = (float) (config("{$sport}.prediction.injury_epa_fallback_multiplier") ?? 1.0);

        if (! is_finite($multiplier) || $multiplier <= 0) {
            return max(0.1, $fallback);
        }

        return max($min, min($max, $multiplier));
    }

    private function supportsEpaWeightedInjuryImpact(string $sport): bool
    {
        $enabled = config("{$sport}.prediction.injury_epa_weighting_enabled");
        if (is_bool($enabled)) {
            return $enabled;
        }

        return in_array($sport, ['nba', 'wnba', 'cbb', 'wcbb'], true);
    }

    private function epaProfileForSport(string $sport): string
    {
        $configured = config("{$sport}.prediction.injury_epa_profile");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $sport === 'nba' ? NbaPlayerEpaCalculator::PROFILE_NBA : NbaPlayerEpaCalculator::PROFILE_CBB;
    }

    /**
     * Build a safe numeric SQL expression that only references existing columns.
     *
     * @param  array<int, string>  $candidates
     */
    private function coalescedNumericExpr(string $table, array $candidates, string $alias): string
    {
        $existing = array_values(array_filter(
            $candidates,
            fn (string $column): bool => $this->hasColumn($table, $column)
        ));

        if ($existing === []) {
            return "0 as `{$alias}`";
        }

        if (count($existing) === 1) {
            return "COALESCE(`{$existing[0]}`, 0) as `{$alias}`";
        }

        $columns = implode(', ', array_map(
            fn (string $column): string => "`{$column}`",
            $existing
        ));

        return "COALESCE({$columns}, 0) as `{$alias}`";
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = "{$table}.{$column}";
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        return $this->columnExistsCache[$cacheKey] = Schema::hasColumn($table, $column);
    }
}
