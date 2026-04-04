<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\TeamMetric;

class HistoricalPredictionContextService
{
    /**
     * @return array{
     *   available: bool,
     *   home: array<string, float|int|null>,
     *   away: array<string, float|int|null>,
     *   spread_adjustment: float,
     *   total_adjustment: float
     * }
     */
    public function forGame(Game $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! (bool) config('mlb.prediction.historical_priors.enabled', true)) {
            return $this->emptyContext();
        }

        $home = $this->teamPrior($game, $homeTeamId);
        $away = $this->teamPrior($game, $awayTeamId);

        if (($home['available'] ?? false) !== true || ($away['available'] ?? false) !== true) {
            return [
                'available' => false,
                'home' => $home,
                'away' => $away,
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
            ];
        }

        $spreadMultiplier = (float) config('mlb.prediction.historical_priors.spread_run_diff_multiplier', 0.35);
        $maxSpreadAdjustment = (float) config('mlb.prediction.historical_priors.max_spread_adjustment', 0.8);
        $maxTotalAdjustment = (float) config('mlb.prediction.historical_priors.max_total_adjustment', 0.9);
        $totalRunEnvironmentMultiplier = (float) config('mlb.prediction.historical_priors.total_run_environment_multiplier', 0.30);
        $baseRuns = (float) config('mlb.prediction.total_model.base_runs', config('mlb.elo.average_runs_per_game', 9.0));

        $runDifferentialGap = (float) ($home['run_differential_per_game'] ?? 0.0) - (float) ($away['run_differential_per_game'] ?? 0.0);
        $rawSpreadAdjustment = $runDifferentialGap * $spreadMultiplier;

        $expectedRunEnvironment = (
            ((float) ($home['runs_per_game'] ?? 0.0) + (float) ($away['runs_allowed_per_game'] ?? 0.0))
            + ((float) ($away['runs_per_game'] ?? 0.0) + (float) ($home['runs_allowed_per_game'] ?? 0.0))
        ) / 2;
        $rawTotalAdjustment = ($expectedRunEnvironment - $baseRuns) * $totalRunEnvironmentMultiplier;

        return [
            'available' => true,
            'home' => $home,
            'away' => $away,
            'spread_adjustment' => round($this->clamp($rawSpreadAdjustment, $maxSpreadAdjustment), 2),
            'total_adjustment' => round($this->clamp($rawTotalAdjustment, $maxTotalAdjustment), 2),
        ];
    }

    /**
     * @return array<string, float|int|null|bool>
     */
    private function teamPrior(Game $game, int $teamId): array
    {
        $lookbackSeasons = max(1, (int) config('mlb.prediction.historical_priors.lookback_seasons', 2));
        $decay = (float) config('mlb.prediction.historical_priors.season_decay', 0.65);
        $regularSeasonType = (string) config('mlb.season.types.regular', 2);

        $rows = TeamMetric::query()
            ->where('team_id', $teamId)
            ->where('season', '<', (int) $game->season)
            ->where(function ($query) use ($regularSeasonType) {
                $query->where('season_type', $regularSeasonType)
                    ->orWhereNull('season_type');
            })
            ->orderByDesc('season')
            ->limit($lookbackSeasons)
            ->get([
                'season',
                'wins',
                'losses',
                'runs_per_game',
                'runs_allowed_per_game',
                'run_differential_per_game',
                'ops',
                'whip',
            ]);

        if ($rows->isEmpty()) {
            return [
                'available' => false,
                'sample_seasons' => 0,
                'weighted_games' => 0.0,
                'runs_per_game' => null,
                'runs_allowed_per_game' => null,
                'run_differential_per_game' => null,
                'ops' => null,
                'whip' => null,
            ];
        }

        $totals = [
            'weight' => 0.0,
            'weighted_games' => 0.0,
            'runs_per_game' => 0.0,
            'runs_allowed_per_game' => 0.0,
            'run_differential_per_game' => 0.0,
            'ops' => 0.0,
            'whip' => 0.0,
        ];

        foreach ($rows as $index => $row) {
            $seasonWeight = pow($decay, $index);
            $games = max(1, (int) ($row->wins ?? 0) + (int) ($row->losses ?? 0));
            $weight = $seasonWeight * min(1.0, $games / 162);

            $totals['weight'] += $weight;
            $totals['weighted_games'] += $games * $weight;
            $totals['runs_per_game'] += (float) ($row->runs_per_game ?? 0.0) * $weight;
            $totals['runs_allowed_per_game'] += (float) ($row->runs_allowed_per_game ?? 0.0) * $weight;
            $totals['run_differential_per_game'] += (float) ($row->run_differential_per_game ?? 0.0) * $weight;
            $totals['ops'] += (float) ($row->ops ?? 0.0) * $weight;
            $totals['whip'] += (float) ($row->whip ?? 0.0) * $weight;
        }

        $weight = max($totals['weight'], 0.0001);

        return [
            'available' => true,
            'sample_seasons' => $rows->count(),
            'weighted_games' => round($totals['weighted_games'] / $weight, 1),
            'runs_per_game' => round($totals['runs_per_game'] / $weight, 3),
            'runs_allowed_per_game' => round($totals['runs_allowed_per_game'] / $weight, 3),
            'run_differential_per_game' => round($totals['run_differential_per_game'] / $weight, 3),
            'ops' => round($totals['ops'] / $weight, 3),
            'whip' => round($totals['whip'] / $weight, 3),
        ];
    }

    /**
     * @return array{
     *   available: bool,
     *   home: array<string, float|int|null>,
     *   away: array<string, float|int|null>,
     *   spread_adjustment: float,
     *   total_adjustment: float
     * }
     */
    private function emptyContext(): array
    {
        return [
            'available' => false,
            'home' => [],
            'away' => [],
            'spread_adjustment' => 0.0,
            'total_adjustment' => 0.0,
        ];
    }

    private function clamp(float $value, float $maxMagnitude): float
    {
        $maxMagnitude = abs($maxMagnitude);

        return max(-$maxMagnitude, min($maxMagnitude, $value));
    }
}
