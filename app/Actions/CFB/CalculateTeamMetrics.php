<?php

namespace App\Actions\CFB;

use App\Concerns\FiltersTeamGames;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'CFB');

        if ($games->isEmpty()) {
            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        // Gather CFB-specific points data
        $pointsScored = [];
        $pointsAllowed = [];

        foreach ($games as $game) {
            $isHome = $game->home_team_id === $team->id;

            if ($isHome) {
                $pointsScored[] = $game->home_score ?? 0;
                $pointsAllowed[] = $game->away_score ?? 0;
            } else {
                $pointsScored[] = $game->away_score ?? 0;
                $pointsAllowed[] = $game->home_score ?? 0;
            }
        }

        if (empty($teamStats)) {
            return null;
        }

        // Calculate metrics
        $pointsPerGame = $this->calculateAverage($pointsScored);
        $pointsAllowedPerGame = $this->calculateAverage($pointsAllowed);
        $offensiveRating = $pointsPerGame;
        $defensiveRating = $pointsAllowedPerGame;
        $netRating = $offensiveRating - $defensiveRating;

        $yardsPerGame = $this->calculateAverageYards($teamStats);
        $yardsAllowedPerGame = $this->calculateAverageYards($opponentStats);
        $passingYardsPerGame = $this->calculateAveragePassingYards($teamStats);
        $rushingYardsPerGame = $this->calculateAverageRushingYards($teamStats);
        $turnoverDifferential = $this->calculateTurnoverDifferential($teamStats, $opponentStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        // Update or create team metric
        return TeamMetric::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            [
                // Rating metrics: 1 decimal
                'offensive_rating' => round($offensiveRating, 1),
                'defensive_rating' => round($defensiveRating, 1),
                'net_rating' => round($netRating, 1),
                'points_per_game' => round($pointsPerGame, 1),
                'points_allowed_per_game' => round($pointsAllowedPerGame, 1),
                'yards_per_game' => round($yardsPerGame, 1),
                'yards_allowed_per_game' => round($yardsAllowedPerGame, 1),
                'passing_yards_per_game' => round($passingYardsPerGame, 1),
                'rushing_yards_per_game' => round($rushingYardsPerGame, 1),
                'turnover_differential' => round($turnoverDifferential, 1),
                // Strength of Schedule: 3 decimals
                'strength_of_schedule' => round($strengthOfSchedule, 3),
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    /**
     * Calculate simple average of an array of values.
     *
     * Formula: Sum of all values / Count of values
     *
     * Used for calculating points per game and points allowed per game.
     *
     * @param  array<int, float>  $values  Array of numeric values
     * @return float Average value, or 0 if array is empty
     */
    protected function calculateAverage(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Calculate average total yards per game.
     *
     * Formula: Sum of total yards / Number of games
     *
     * Includes both passing and rushing yards. Higher values indicate
     * better offensive production. Total yards is a comprehensive measure
     * of offensive efficiency in college football.
     *
     * Expected Range: 250-550 yards per game (CFB)
     *
     * @param  array<int, \App\Models\CFB\TeamStat>  $teamStats  Team statistics records
     * @return float Average total yards per game
     */
    protected function calculateAverageYards(array $teamStats): float
    {
        if (empty($teamStats)) {
            return 0;
        }

        $totalYards = 0;
        foreach ($teamStats as $stat) {
            $totalYards += $stat->total_yards ?? 0;
        }

        return $totalYards / count($teamStats);
    }

    /**
     * Calculate average passing yards per game.
     *
     * Formula: Sum of passing yards / Number of games
     *
     * Measures the team's passing offense effectiveness. In college football,
     * passing efficiency varies widely between teams depending on offensive
     * philosophy (pro-style, air raid, option, etc.).
     *
     * Expected Range: 150-350 yards per game (CFB)
     *
     * @param  array<int, \App\Models\CFB\TeamStat>  $teamStats  Team statistics records
     * @return float Average passing yards per game
     */
    protected function calculateAveragePassingYards(array $teamStats): float
    {
        if (empty($teamStats)) {
            return 0;
        }

        $totalYards = 0;
        foreach ($teamStats as $stat) {
            $totalYards += $stat->passing_yards ?? 0;
        }

        return $totalYards / count($teamStats);
    }

    /**
     * Calculate average rushing yards per game.
     *
     * Formula: Sum of rushing yards / Number of games
     *
     * Measures the team's rushing offense effectiveness. College football has
     * greater diversity in rushing attack schemes than the NFL, from traditional
     * power running to option-based offenses.
     *
     * Expected Range: 100-300 yards per game (CFB)
     *
     * @param  array<int, \App\Models\CFB\TeamStat>  $teamStats  Team statistics records
     * @return float Average rushing yards per game
     */
    protected function calculateAverageRushingYards(array $teamStats): float
    {
        if (empty($teamStats)) {
            return 0;
        }

        $totalYards = 0;
        foreach ($teamStats as $stat) {
            $totalYards += $stat->rushing_yards ?? 0;
        }

        return $totalYards / count($teamStats);
    }

    /**
     * Calculate turnover differential per game.
     *
     * Formula: (Opponent Turnovers - Team Turnovers) / Games
     * Where: Turnovers = Interceptions + Fumbles Lost
     *
     * A positive value indicates the team forces more turnovers than it commits,
     * which correlates strongly with winning percentage. Turnover margin is one of
     * the most predictive statistics in college football, often determining close
     * games. The impact can be even greater than in the NFL due to talent disparities
     * between teams.
     *
     * Expected Range: -3 to +3 per game (CFB)
     *
     * @param  array<int, \App\Models\CFB\TeamStat>  $teamStats  Team's statistics
     * @param  array<int, \App\Models\CFB\TeamStat>  $opponentStats  Opponent statistics
     * @return float Average turnover differential per game
     */
    protected function calculateTurnoverDifferential(array $teamStats, array $opponentStats): float
    {
        $teamTurnovers = 0;
        $opponentTurnovers = 0;

        foreach ($teamStats as $stat) {
            $teamTurnovers += ($stat->interceptions ?? 0) + ($stat->fumbles_lost ?? 0);
        }

        foreach ($opponentStats as $stat) {
            $opponentTurnovers += ($stat->interceptions ?? 0) + ($stat->fumbles_lost ?? 0);
        }

        $gameCount = max(count($teamStats), 1);

        // Positive differential means team forces more turnovers than it commits
        return ($opponentTurnovers - $teamTurnovers) / $gameCount;
    }

    public function executeForAllTeams(int $season): int
    {
        $teams = Team::all();
        $calculated = 0;

        foreach ($teams as $team) {
            $metric = $this->execute($team, $season);
            if ($metric) {
                $calculated++;
            }
        }

        return $calculated;
    }
}
