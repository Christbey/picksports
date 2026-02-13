<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use Illuminate\Support\Collection;

class TunePossessionCoefficient
{
    /**
     * Test different possession coefficients and find the optimal value
     * by comparing against KenPom's published values.
     *
     * @param  array  $kenPomData  Array of ['abbreviation' => abbr, 'adj_o' => float, 'adj_d' => float, 'net_rtg' => float]
     * @param  int  $season  Season to analyze
     * @param  int  $minimumGames  Minimum games threshold (default 8)
     * @param  float  $minCoeff  Minimum coefficient to test (default 0.40)
     * @param  float  $maxCoeff  Maximum coefficient to test (default 0.48)
     * @param  float  $step  Step size for testing (default 0.01)
     * @return array Analysis results with optimal coefficient
     */
    public function execute(
        array $kenPomData,
        int $season,
        int $minimumGames = 8,
        float $minCoeff = 0.40,
        float $maxCoeff = 0.48,
        float $step = 0.01
    ): array {
        // Build KenPom lookup by team abbreviation
        $kenPomLookup = collect($kenPomData)->keyBy('abbreviation');

        // Test each coefficient
        $results = [];
        $currentCoeff = $minCoeff;

        while ($currentCoeff <= $maxCoeff) {
            $metrics = $this->calculateMetricsWithCoefficient($season, $currentCoeff, $minimumGames);
            $error = $this->calculateError($metrics, $kenPomLookup);

            $results[] = [
                'coefficient' => round($currentCoeff, 3),
                'mean_absolute_error' => $error['mae'],
                'root_mean_square_error' => $error['rmse'],
                'teams_compared' => $error['count'],
            ];

            $currentCoeff += $step;
        }

        // Find optimal coefficient (minimum RMSE)
        $optimal = collect($results)->sortBy('root_mean_square_error')->first();

        return [
            'optimal_coefficient' => $optimal['coefficient'],
            'optimal_rmse' => $optimal['root_mean_square_error'],
            'optimal_mae' => $optimal['mean_absolute_error'],
            'teams_compared' => $optimal['teams_compared'],
            'all_results' => $results,
        ];
    }

    /**
     * Calculate metrics for all teams using a specific coefficient.
     */
    protected function calculateMetricsWithCoefficient(int $season, float $coefficient, int $minimumGames): Collection
    {
        $teams = Team::all();
        $metrics = collect();

        foreach ($teams as $team) {
            $teamMetrics = $this->calculateTeamMetrics($team, $season, $coefficient, $minimumGames);

            if ($teamMetrics) {
                $metrics->push($teamMetrics);
            }
        }

        return $metrics;
    }

    /**
     * Calculate metrics for a single team using a specific coefficient.
     */
    protected function calculateTeamMetrics(Team $team, int $season, float $coefficient, int $minimumGames): ?array
    {
        // Get all completed games for this team in the season
        $games = Game::query()
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats'])
            ->get();

        if ($games->count() < $minimumGames) {
            return null;
        }

        // Gather team stats
        $teamStats = [];
        $opponentStats = [];

        foreach ($games as $game) {
            $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
            $opponentId = $game->home_team_id === $team->id ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);

            if ($teamStat) {
                $teamStats[] = $teamStat;
            }

            if ($opponentStat) {
                $opponentStats[] = $opponentStat;
            }
        }

        if (empty($teamStats)) {
            return null;
        }

        // Calculate metrics using the given coefficient
        $offensiveEfficiency = $this->calculateOffensiveEfficiency($teamStats, $coefficient);
        $defensiveEfficiency = $this->calculateDefensiveEfficiency($opponentStats, $coefficient);
        $netRating = $offensiveEfficiency - $defensiveEfficiency;

        return [
            'team_id' => $team->id,
            'abbreviation' => $team->abbreviation,
            'offensive_efficiency' => round($offensiveEfficiency, 1),
            'defensive_efficiency' => round($defensiveEfficiency, 1),
            'net_rating' => round($netRating, 1),
            'games_played' => count($teamStats),
        ];
    }

    /**
     * Calculate offensive efficiency using a specific coefficient.
     */
    protected function calculateOffensiveEfficiency(array $teamStats, float $coefficient): float
    {
        $totalPoints = 0;
        $totalPossessions = 0;

        foreach ($teamStats as $stat) {
            $totalPoints += $stat->points ?? 0;
            $totalPossessions += $this->estimatePossessions($stat, $coefficient);
        }

        if ($totalPossessions == 0) {
            return 0;
        }

        return ($totalPoints / $totalPossessions) * 100;
    }

    /**
     * Calculate defensive efficiency using a specific coefficient.
     */
    protected function calculateDefensiveEfficiency(array $opponentStats, float $coefficient): float
    {
        $totalPoints = 0;
        $totalPossessions = 0;

        foreach ($opponentStats as $stat) {
            $totalPoints += $stat->points ?? 0;
            $totalPossessions += $this->estimatePossessions($stat, $coefficient);
        }

        if ($totalPossessions == 0) {
            return 0;
        }

        return ($totalPoints / $totalPossessions) * 100;
    }

    /**
     * Estimate possessions using Dean Oliver's formula with a variable coefficient.
     */
    protected function estimatePossessions(TeamStat $stat, float $coefficient): float
    {
        // Poss = FGA - ORB + TO + (coefficient * FTA)
        $fga = $stat->field_goals_attempted ?? 0;
        $orb = $stat->offensive_rebounds ?? 0;
        $to = $stat->turnovers ?? 0;
        $fta = $stat->free_throws_attempted ?? 0;

        return $fga - $orb + $to + ($coefficient * $fta);
    }

    /**
     * Calculate error metrics by comparing our calculations to KenPom.
     */
    protected function calculateError(Collection $ourMetrics, Collection $kenPomLookup): array
    {
        $errors = [];

        foreach ($ourMetrics as $metric) {
            $kenPom = $kenPomLookup->get($metric['abbreviation']);

            if (! $kenPom) {
                continue;
            }

            // Calculate error for net rating (most important metric)
            $error = abs($metric['net_rating'] - $kenPom['net_rtg']);
            $errors[] = $error;
        }

        if (empty($errors)) {
            return [
                'mae' => 999999,
                'rmse' => 999999,
                'count' => 0,
            ];
        }

        // Mean Absolute Error
        $mae = array_sum($errors) / count($errors);

        // Root Mean Square Error
        $squaredErrors = array_map(fn ($e) => $e * $e, $errors);
        $rmse = sqrt(array_sum($squaredErrors) / count($squaredErrors));

        return [
            'mae' => round($mae, 3),
            'rmse' => round($rmse, 3),
            'count' => count($errors),
        ];
    }
}
