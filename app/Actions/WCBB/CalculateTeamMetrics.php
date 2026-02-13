<?php

namespace App\Actions\WCBB;

use App\Concerns\FiltersTeamGames;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\MetricValidator;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'WCBB');

        $gamesPlayed = $games->count();

        if ($gamesPlayed === 0) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => "{$team->school} {$team->mascot}",
                'season' => $season,
                'sport' => 'wcbb',
            ]);

            return null;
        }

        $meetsMinimum = $gamesPlayed >= config('wcbb.metrics.minimum_games');

        extract($this->gatherTeamStatsFromGames($games, $team));

        // Gather home/away splits
        $homeTeamStats = [];
        $awayTeamStats = [];
        $homeOpponentStats = [];
        $awayOpponentStats = [];

        foreach ($games as $game) {
            $isHome = $game->home_team_id === $team->id;

            $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);

            if ($teamStat) {
                if ($isHome) {
                    $homeTeamStats[] = $teamStat;
                } else {
                    $awayTeamStats[] = $teamStat;
                }
            }

            if ($opponentStat) {
                if ($isHome) {
                    $homeOpponentStats[] = $opponentStat;
                } else {
                    $awayOpponentStats[] = $opponentStat;
                }
            }
        }

        if (empty($teamStats)) {
            return null;
        }

        // Calculate season-long metrics
        $offensiveEfficiency = $this->calculateOffensiveEfficiency($teamStats);
        $defensiveEfficiency = $this->calculateDefensiveEfficiency($opponentStats);
        $netRating = $offensiveEfficiency - $defensiveEfficiency;
        $tempo = $this->calculateTempo($teamStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        // Calculate rolling window metrics (last N games)
        $rollingMetrics = $this->calculateRollingMetrics($teamStats, $opponentStats);

        // Calculate home/away splits
        $homeMetrics = $this->calculateHomeAwayMetrics($homeTeamStats, $homeOpponentStats);
        $awayMetrics = $this->calculateHomeAwayMetrics($awayTeamStats, $awayOpponentStats);

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => "{$team->school} {$team->mascot}",
            'season' => $season,
            'sport' => 'wcbb',
            'games_count' => $gamesPlayed,
            'offensive_efficiency' => round($offensiveEfficiency, 1),
            'defensive_efficiency' => round($defensiveEfficiency, 1),
            'net_rating' => round($netRating, 1),
        ]);

        // Validate metrics before saving
        if ($meetsMinimum) {
            $validator = new MetricValidator;
            $validator->validate([
                'offensive_efficiency' => $offensiveEfficiency,
                'defensive_efficiency' => $defensiveEfficiency,
                'tempo' => $tempo,
            ], 'wcbb', [
                'team_id' => $team->id,
                'team_name' => "{$team->school} {$team->mascot}",
                'season' => $season,
            ]);
        }

        // Update or create team metric
        return TeamMetric::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            [
                // Season-long metrics - Efficiency/Rating: 1 decimal
                'offensive_efficiency' => round($offensiveEfficiency, 1),
                'defensive_efficiency' => round($defensiveEfficiency, 1),
                'net_rating' => round($netRating, 1),
                'tempo' => round($tempo, 1),
                // Strength of Schedule: 3 decimals
                'strength_of_schedule' => round($strengthOfSchedule, 3),
                'games_played' => $gamesPlayed,
                'meets_minimum' => $meetsMinimum,
                'possession_coefficient' => config('wcbb.metrics.possession_coefficient'),
                // Rolling window metrics (last N games)
                'rolling_offensive_efficiency' => $rollingMetrics['offensive_efficiency'],
                'rolling_defensive_efficiency' => $rollingMetrics['defensive_efficiency'],
                'rolling_net_rating' => $rollingMetrics['net_rating'],
                'rolling_tempo' => $rollingMetrics['tempo'],
                'rolling_games_count' => $rollingMetrics['games_count'],
                // Home/away splits
                'home_offensive_efficiency' => $homeMetrics['offensive_efficiency'],
                'home_defensive_efficiency' => $homeMetrics['defensive_efficiency'],
                'away_offensive_efficiency' => $awayMetrics['offensive_efficiency'],
                'away_defensive_efficiency' => $awayMetrics['defensive_efficiency'],
                'home_games' => $homeMetrics['games_count'],
                'away_games' => $awayMetrics['games_count'],
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
     * Expected Range: 80-120 points per 100 possessions (WCBB)
     *
     * @param  array<int, \App\Models\WCBB\TeamStat>  $teamStats  Team statistics records
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
     * Expected Range: 80-120 points per 100 possessions (WCBB)
     *
     * @param  array<int, \App\Models\WCBB\TeamStat>  $opponentStats  Opponent statistics records
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
     * Expected Range: 55-80 possessions per game (WCBB - 40 minute games)
     *
     * @param  array<int, \App\Models\WCBB\TeamStat>  $teamStats  Team statistics records
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

        // Average possessions per game (40 minutes for college)
        return $totalPossessions / $gameCount;
    }

    /**
     * Estimate possessions using Dean Oliver's formula with women's college basketball coefficient.
     *
     * Formula: FGA - ORB + TO + (0.40 * FTA)
     *
     * The coefficient is tuned to 0.40 for women's college basketball (vs 0.44 for WNBA) to
     * account for differences in free throw situations, game pace, and playing style compared
     * to professional women's basketball.
     *
     * Reference: "Basketball on Paper" by Dean Oliver (adapted for women's college basketball)
     * Expected Range: 55-80 possessions per game (WCBB - 40 minute games)
     *
     * @param  \App\Models\WCBB\TeamStat  $stat  Team statistics for a single game
     * @return float Estimated possessions
     */
    protected function estimatePossessions(TeamStat $stat): float
    {
        // Dean Oliver's possession formula with WCBB-optimized coefficient
        // Poss = FGA - ORB + TO + (coefficient * FTA)
        // Coefficient tuned to 0.40 for WCBB (vs 0.44 for NBA)
        $fga = $stat->field_goals_attempted ?? 0;
        $orb = $stat->offensive_rebounds ?? 0;
        $to = $stat->turnovers ?? 0;
        $fta = $stat->free_throws_attempted ?? 0;

        return $fga - $orb + $to + (config('wcbb.metrics.possession_coefficient') * $fta);
    }

    /**
     * Calculate rolling window metrics (last N games).
     *
     * Rolling metrics capture recent team performance by analyzing only the most recent
     * games. This provides insights into current form, momentum, and adjustments teams
     * have made during the season. The window size is configurable via the config file.
     *
     * Returns an array containing:
     * - offensive_efficiency: Points per 100 possessions (last N games)
     * - defensive_efficiency: Opponent points per 100 possessions (last N games)
     * - net_rating: Difference between offensive and defensive efficiency
     * - tempo: Average possessions per game (last N games)
     * - games_count: Number of games included in calculation
     *
     * Window Size: Configured via `wcbb.metrics.rolling_window_size`
     *
     * @param  array<int, \App\Models\WCBB\TeamStat>  $teamStats  Team statistics records
     * @param  array<int, \App\Models\WCBB\TeamStat>  $opponentStats  Opponent statistics records
     * @return array<string, float|int|null> Rolling metrics for the last N games
     */
    protected function calculateRollingMetrics(array $teamStats, array $opponentStats): array
    {
        // Get the last N games
        $rollingTeamStats = array_slice($teamStats, -config('wcbb.metrics.rolling_window_size'));
        $rollingOpponentStats = array_slice($opponentStats, -config('wcbb.metrics.rolling_window_size'));

        $gamesCount = count($rollingTeamStats);

        if ($gamesCount === 0) {
            return [
                'offensive_efficiency' => null,
                'defensive_efficiency' => null,
                'net_rating' => null,
                'tempo' => null,
                'games_count' => 0,
            ];
        }

        $offEff = $this->calculateOffensiveEfficiency($rollingTeamStats);
        $defEff = $this->calculateDefensiveEfficiency($rollingOpponentStats);
        $netRtg = $offEff - $defEff;
        $tempo = $this->calculateTempo($rollingTeamStats);

        return [
            'offensive_efficiency' => round($offEff, 1),
            'defensive_efficiency' => round($defEff, 1),
            'net_rating' => round($netRtg, 1),
            'tempo' => round($tempo, 1),
            'games_count' => $gamesCount,
        ];
    }

    /**
     * Calculate home/away split metrics.
     *
     * Home court advantage is a well-documented phenomenon in basketball. This method
     * calculates offensive and defensive efficiency separately for home and away games,
     * allowing for more accurate predictions based on game location. Teams typically
     * perform better at home due to familiar environment, crowd support, and reduced travel.
     *
     * Returns an array containing:
     * - offensive_efficiency: Points per 100 possessions (home or away only)
     * - defensive_efficiency: Opponent points per 100 possessions (home or away only)
     * - games_count: Number of games included in calculation
     *
     * @param  array<int, \App\Models\WCBB\TeamStat>  $teamStats  Team statistics for home or away games
     * @param  array<int, \App\Models\WCBB\TeamStat>  $opponentStats  Opponent statistics for home or away games
     * @return array<string, float|int|null> Home or away metrics
     */
    protected function calculateHomeAwayMetrics(array $teamStats, array $opponentStats): array
    {
        $gamesCount = count($teamStats);

        if ($gamesCount === 0) {
            return [
                'offensive_efficiency' => null,
                'defensive_efficiency' => null,
                'games_count' => 0,
            ];
        }

        $offEff = $this->calculateOffensiveEfficiency($teamStats);
        $defEff = $this->calculateDefensiveEfficiency($opponentStats);

        return [
            'offensive_efficiency' => round($offEff, 1),
            'defensive_efficiency' => round($defEff, 1),
            'games_count' => $gamesCount,
        ];
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

        // After all raw metrics are calculated, run opponent adjustments
        $this->calculateOpponentAdjustments($season);

        return $calculated;
    }

    protected function calculateOpponentAdjustments(int $season): void
    {
        // Get all teams with metrics that meet minimum
        $metrics = TeamMetric::query()
            ->where('season', $season)
            ->where('meets_minimum', true)
            ->with('team')
            ->get();

        if ($metrics->isEmpty()) {
            return;
        }

        // Get all games for the season with team stats
        $games = Game::query()
            ->where('season', $season)
            ->where('status', config('wcbb.statuses.final'))
            ->with(['teamStats'])
            ->get();

        // Use the OpponentAdjustmentCalculator service
        $calculator = new \App\Services\OpponentAdjustmentCalculator(
            'wcbb',
            $season,
            fn ($stat) => $this->estimatePossessions($stat)
        );

        $calculator->calculate($metrics, $games);

        // Set iteration count on all metrics
        $calculator->setIterationCount($metrics, config('wcbb.metrics.max_adjustment_iterations'));
    }
}
