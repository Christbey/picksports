<?php

namespace App\Actions\NBA;

use App\Concerns\FiltersTeamGames;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use App\Services\MetricValidator;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'NBA');

        if ($games->isEmpty()) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => "{$team->city} {$team->name}",
                'season' => $season,
                'sport' => 'nba',
            ]);

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        if (empty($teamStats)) {
            return null;
        }

        // Calculate metrics
        $offensiveEfficiency = $this->calculateOffensiveEfficiency($teamStats);
        $defensiveEfficiency = $this->calculateDefensiveEfficiency($opponentStats);
        $netRating = $offensiveEfficiency - $defensiveEfficiency;
        $tempo = $this->calculateTempo($teamStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
            'sport' => 'nba',
            'games_count' => count($teamStats),
            'offensive_efficiency' => round($offensiveEfficiency, 1),
            'defensive_efficiency' => round($defensiveEfficiency, 1),
            'net_rating' => round($netRating, 1),
        ]);

        // Validate metrics before saving
        $validator = new MetricValidator;
        $validator->validate([
            'offensive_efficiency' => $offensiveEfficiency,
            'defensive_efficiency' => $defensiveEfficiency,
            'tempo' => $tempo,
        ], 'nba', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
        ]);

        // Update or create team metric
        return TeamMetric::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            [
                // Efficiency/Rating metrics: 1 decimal
                'offensive_efficiency' => round($offensiveEfficiency, 1),
                'defensive_efficiency' => round($defensiveEfficiency, 1),
                'net_rating' => round($netRating, 1),
                'tempo' => round($tempo, 1),
                // Strength of Schedule: 3 decimals
                'strength_of_schedule' => round($strengthOfSchedule, 3),
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    /**
     * Calculate offensive efficiency.
     *
     * Formula: (Total Points / Total Possessions) * 100
     *
     * Offensive efficiency measures how many points a team scores per 100 possessions.
     * This metric normalizes scoring across different pace environments, making it easier
     * to compare teams that play at different speeds. Higher values indicate more efficient
     * scoring offenses.
     *
     * Expected Range: 90-130 points per 100 possessions (NBA)
     *
     * @param  array<int, \App\Models\NBA\TeamStat>  $teamStats  Team statistics records
     * @return float Points per 100 possessions
     */
    protected function calculateOffensiveEfficiency(array $teamStats): float
    {
        $totalPoints = 0;
        $totalPossessions = 0;

        foreach ($teamStats as $stat) {
            $totalPoints += $stat->points ?? 0;
            $totalPossessions += $stat->possessions ?? $this->estimatePossessions($stat);
        }

        if ($totalPossessions == 0) {
            return 0;
        }

        // Points per 100 possessions
        return ($totalPoints / $totalPossessions) * 100;
    }

    /**
     * Calculate defensive efficiency.
     *
     * Formula: (Opponent Total Points / Opponent Total Possessions) * 100
     *
     * Defensive efficiency measures how many points a team allows per 100 possessions.
     * This metric normalizes defense across different pace environments. Lower values
     * indicate better defensive performance, as the team is allowing fewer points per
     * possession.
     *
     * Expected Range: 90-130 points per 100 possessions (NBA)
     *
     * @param  array<int, \App\Models\NBA\TeamStat>  $opponentStats  Opponent statistics records
     * @return float Opponent points per 100 possessions
     */
    protected function calculateDefensiveEfficiency(array $opponentStats): float
    {
        $totalPoints = 0;
        $totalPossessions = 0;

        foreach ($opponentStats as $stat) {
            $totalPoints += $stat->points ?? 0;
            $totalPossessions += $stat->possessions ?? $this->estimatePossessions($stat);
        }

        if ($totalPossessions == 0) {
            return 0;
        }

        // Opponent points per 100 possessions
        return ($totalPoints / $totalPossessions) * 100;
    }

    /**
     * Calculate tempo (pace).
     *
     * Formula: Total Possessions / Number of Games
     *
     * Tempo measures the average number of possessions a team uses per game.
     * Teams with higher tempo play faster and have more possessions, while teams
     * with lower tempo play slower and more deliberately. This metric is crucial
     * for understanding team playing style and for properly contextualizing other
     * efficiency metrics.
     *
     * Expected Range: 90-110 possessions per game (NBA - 48 minute games)
     *
     * @param  array<int, \App\Models\NBA\TeamStat>  $teamStats  Team statistics records
     * @return float Average possessions per game
     */
    protected function calculateTempo(array $teamStats): float
    {
        $totalPossessions = 0;
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalPossessions += $stat->possessions ?? $this->estimatePossessions($stat);
        }

        if ($gameCount == 0) {
            return 0;
        }

        // Average possessions per game (48 minutes)
        return $totalPossessions / $gameCount;
    }

    /**
     * Estimate possessions using Dean Oliver's formula.
     *
     * Formula: FGA - ORB + TO + (0.44 * FTA)
     *
     * This formula estimates the number of possessions a team used in a game.
     * The 0.44 coefficient accounts for the fact that not all free throw attempts
     * end a possession (and-1 situations, technical fouls, missed first of two, etc.).
     *
     * Reference: "Basketball on Paper" by Dean Oliver
     * Expected Range: 90-110 possessions per game (NBA)
     *
     * @param  \App\Models\NBA\TeamStat  $stat  Team statistics for a single game
     * @return float Estimated possessions
     */
    protected function estimatePossessions(TeamStat $stat): float
    {
        // Dean Oliver's possession formula
        // Poss = FGA - ORB + TO + (coefficient * FTA)
        $fga = $stat->field_goals_attempted ?? 0;
        $orb = $stat->offensive_rebounds ?? 0;
        $to = $stat->turnovers ?? 0;
        $fta = $stat->free_throws_attempted ?? 0;

        return $fga - $orb + $to + (config('nba.possession_coefficient') * $fta);
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
