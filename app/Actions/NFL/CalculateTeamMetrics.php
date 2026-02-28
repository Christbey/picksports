<?php

namespace App\Actions\NFL;

use App\Actions\Sports\Concerns\CalculatesGridironTeamMetrics;
use App\Concerns\FiltersTeamGames;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Services\MetricValidator;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use FiltersTeamGames, CalculatesGridironTeamMetrics;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'NFL');

        if ($games->isEmpty()) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => "{$team->city} {$team->name}",
                'season' => $season,
                'sport' => 'nfl',
            ]);

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        // Gather NFL-specific points data
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

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
            'sport' => 'nfl',
            'games_count' => $games->count(),
            'offensive_rating' => round($offensiveRating, 1),
            'defensive_rating' => round($defensiveRating, 1),
            'net_rating' => round($netRating, 1),
        ]);

        // Validate metrics before saving
        $validator = new MetricValidator;
        $validator->validate([
            'offensive_rating' => $offensiveRating,
            'defensive_rating' => $defensiveRating,
            'net_rating' => $netRating,
            'yards_per_game' => $yardsPerGame,
            'turnover_differential' => $turnoverDifferential,
        ], 'nfl', [
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
