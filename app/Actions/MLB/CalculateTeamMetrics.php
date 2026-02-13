<?php

namespace App\Actions\MLB;

use App\Concerns\FiltersTeamGames;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Services\MetricValidator;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'MLB');

        if ($games->isEmpty()) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => "{$team->city} {$team->name}",
                'season' => $season,
                'sport' => 'mlb',
            ]);

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        if (empty($teamStats)) {
            return null;
        }

        // Calculate baseball-specific metrics
        $offensiveRating = $this->calculateOffensiveRating($teamStats);
        $pitchingRating = $this->calculatePitchingRating($teamStats);
        $defensiveRating = $this->calculateDefensiveRating($teamStats);
        $runsPerGame = $this->calculateRunsPerGame($teamStats);
        $runsAllowedPerGame = $this->calculateRunsAllowedPerGame($opponentStats);
        $battingAverage = $this->calculateBattingAverage($teamStats);
        $teamEra = $this->calculateTeamEra($teamStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
            'sport' => 'mlb',
            'games_count' => count($teamStats),
            'offensive_rating' => round($offensiveRating, 1),
            'pitching_rating' => round($pitchingRating, 1),
            'defensive_rating' => round($defensiveRating, 1),
        ]);

        // Validate metrics before saving
        $validator = new MetricValidator;
        $validator->validate([
            'offensive_rating' => $offensiveRating,
            'pitching_rating' => $pitchingRating,
            'team_era' => $teamEra,
            'batting_average' => $battingAverage,
        ], 'mlb', [
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
                'pitching_rating' => round($pitchingRating, 1),
                'defensive_rating' => round($defensiveRating, 1),
                'runs_per_game' => round($runsPerGame, 1),
                'runs_allowed_per_game' => round($runsAllowedPerGame, 1),
                // Batting average: 3 decimals
                'batting_average' => round($battingAverage, 3),
                // ERA: 2 decimals
                'team_era' => round($teamEra, 2),
                // Strength of Schedule: 3 decimals
                'strength_of_schedule' => round($strengthOfSchedule, 3),
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    /**
     * Calculate offensive rating.
     *
     * Formula: (Runs/Game * multiplier) + (Batting Avg * multiplier) + (HR Rate * multiplier)
     *
     * Offensive rating combines multiple offensive components into a single metric that
     * represents a team's overall offensive production. This weighted formula considers
     * runs scored (the ultimate offensive goal), batting average (hitting consistency),
     * and home run rate (power hitting). The multipliers are configurable to fine-tune
     * the relative importance of each component.
     *
     * Components:
     * - Runs per game: Total runs divided by games played
     * - Batting average: Hits divided by at-bats
     * - Home run rate: Home runs per game
     *
     * Expected Range: 50-150 (configurable based on multipliers)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Composite offensive rating
     */
    protected function calculateOffensiveRating(array $teamStats): float
    {
        // Offensive Rating based on runs scored, hits, OBP components
        $totalRuns = 0;
        $totalHits = 0;
        $totalAtBats = 0;
        $totalWalks = 0;
        $totalHomeRuns = 0;
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalRuns += $stat->runs ?? 0;
            $totalHits += $stat->hits ?? 0;
            $totalAtBats += $stat->at_bats ?? 0;
            $totalWalks += $stat->walks ?? 0;
            $totalHomeRuns += $stat->home_runs ?? 0;
        }

        if ($gameCount == 0) {
            return 0;
        }

        // Weighted formula: runs per game * multiplier + batting metrics
        $runsPerGame = $totalRuns / $gameCount;
        $battingAvg = $totalAtBats > 0 ? ($totalHits / $totalAtBats) : 0;
        $homeRunRate = $gameCount > 0 ? ($totalHomeRuns / $gameCount) : 0;

        return ($runsPerGame * config('mlb.metrics.offensive_rating.runs_multiplier'))
            + ($battingAvg * config('mlb.metrics.offensive_rating.batting_avg_multiplier'))
            + ($homeRunRate * config('mlb.metrics.offensive_rating.home_run_multiplier'));
    }

    /**
     * Calculate pitching rating.
     *
     * Formula: (ERA Component) + (K's per game) - (Walks per game)
     * Where: ERA Component = max(0, ERA_MAX - (ERA * ERA_SCALE))
     *
     * Pitching rating combines ERA (earned run average), strikeouts, and walks into
     * a composite metric representing overall pitching staff quality. The ERA component
     * is inverted and scaled so that lower ERAs produce higher ratings. Strikeouts are
     * added (more is better) and walks are subtracted (fewer is better).
     *
     * Components:
     * - ERA: (Earned runs / Innings pitched) * 9
     * - Strikeouts per game: Total strikeouts divided by games
     * - Walks per game: Total walks allowed divided by games
     *
     * The ERA_MAX and ERA_SCALE configurables control how ERA contributes to the rating.
     *
     * Expected Range: Varies based on configuration (typically 50-150)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Composite pitching rating
     */
    protected function calculatePitchingRating(array $teamStats): float
    {
        // Pitching Rating based on ERA, strikeouts, walks
        $totalEarnedRuns = 0;
        $totalInningsPitched = 0;
        $totalStrikeouts = 0;
        $totalWalksAllowed = 0;
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalEarnedRuns += $stat->earned_runs ?? 0;
            $totalInningsPitched += $stat->innings_pitched ?? 0;
            $totalStrikeouts += $stat->strikeouts_pitched ?? 0;
            $totalWalksAllowed += $stat->walks_allowed ?? 0;
        }

        if ($totalInningsPitched == 0 || $gameCount == 0) {
            return 0;
        }

        $era = ($totalEarnedRuns / $totalInningsPitched) * 9;
        $strikeoutsPerGame = $totalStrikeouts / $gameCount;
        $walksPerGame = $totalWalksAllowed / $gameCount;

        // Lower ERA = better pitching, more K's = better, fewer walks = better
        // Inverse ERA (max-ERA bounded) + K's per game - walks per game
        $eraComponent = max(
            0,
            config('mlb.metrics.pitching_rating.era_max')
            - ($era * config('mlb.metrics.pitching_rating.era_scale'))
        );

        return $eraComponent + $strikeoutsPerGame - $walksPerGame;
    }

    /**
     * Calculate defensive rating.
     *
     * Formula: (Fielding % * multiplier) + Putouts/Game + Assists/Game - (Errors/Game * multiplier)
     * Where: Fielding % = (Putouts + Assists - Errors) / (Putouts + Assists + Errors)
     *
     * Defensive rating combines fielding percentage, putouts, assists, and errors into
     * a composite metric representing overall defensive quality. Fielding percentage
     * measures how often defensive plays are made successfully. Putouts and assists
     * represent defensive activity, while errors are penalized.
     *
     * Components:
     * - Fielding percentage: Successful plays divided by total defensive chances
     * - Putouts per game: Total putouts divided by games
     * - Assists per game: Total assists divided by games
     * - Errors per game: Total errors divided by games (subtracted)
     *
     * Expected Range: Varies based on configuration (typically 60-90)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Composite defensive rating
     */
    protected function calculateDefensiveRating(array $teamStats): float
    {
        // Defensive Rating based on fielding (errors, putouts, assists)
        $totalErrors = 0;
        $totalPutouts = 0;
        $totalAssists = 0;
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalErrors += $stat->errors ?? 0;
            $totalPutouts += $stat->putouts ?? 0;
            $totalAssists += $stat->assists ?? 0;
        }

        if ($gameCount == 0) {
            return 0;
        }

        $errorsPerGame = $totalErrors / $gameCount;
        $putoutsPerGame = $totalPutouts / $gameCount;
        $assistsPerGame = $totalAssists / $gameCount;

        // Fewer errors = better defense, more putouts/assists = better
        $fieldingPct = ($totalPutouts + $totalAssists) > 0
            ? (($totalPutouts + $totalAssists - $totalErrors) / ($totalPutouts + $totalAssists + $totalErrors))
            : 0;

        return ($fieldingPct * config('mlb.metrics.defensive_rating.fielding_pct_multiplier'))
            + $putoutsPerGame
            + $assistsPerGame
            - ($errorsPerGame * config('mlb.metrics.defensive_rating.errors_multiplier'));
    }

    /**
     * Calculate runs per game.
     *
     * Formula: Total Runs / Number of Games
     *
     * Simple average of runs scored per game. This is the most fundamental offensive
     * metric in baseball, measuring a team's ability to score runs, which is the
     * ultimate goal of offense.
     *
     * Expected Range: 3-6 runs per game (MLB)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Average runs scored per game
     */
    protected function calculateRunsPerGame(array $teamStats): float
    {
        $totalRuns = 0;
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalRuns += $stat->runs ?? 0;
        }

        return $gameCount > 0 ? ($totalRuns / $gameCount) : 0;
    }

    /**
     * Calculate runs allowed per game.
     *
     * Formula: Total Opponent Runs / Number of Games
     *
     * Simple average of runs allowed per game. This is a fundamental defensive metric
     * measuring a team's ability to prevent runs, which encompasses both pitching and
     * defensive performance.
     *
     * Expected Range: 3-6 runs per game (MLB)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $opponentStats  Opponent statistics records
     * @return float Average runs allowed per game
     */
    protected function calculateRunsAllowedPerGame(array $opponentStats): float
    {
        $totalRuns = 0;
        $gameCount = count($opponentStats);

        foreach ($opponentStats as $stat) {
            $totalRuns += $stat->runs ?? 0;
        }

        return $gameCount > 0 ? ($totalRuns / $gameCount) : 0;
    }

    /**
     * Calculate batting average.
     *
     * Formula: Total Hits / Total At-Bats
     *
     * Batting average is one of baseball's most traditional statistics, measuring the
     * frequency with which a batter gets a hit. While modern analytics favor more
     * comprehensive metrics like OBP or OPS, batting average remains a widely
     * understood indicator of hitting ability.
     *
     * Expected Range: .230-.280 team batting average (MLB)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Team batting average (decimal, e.g., 0.265)
     */
    protected function calculateBattingAverage(array $teamStats): float
    {
        $totalHits = 0;
        $totalAtBats = 0;

        foreach ($teamStats as $stat) {
            $totalHits += $stat->hits ?? 0;
            $totalAtBats += $stat->at_bats ?? 0;
        }

        return $totalAtBats > 0 ? ($totalHits / $totalAtBats) : 0;
    }

    /**
     * Calculate team earned run average (ERA).
     *
     * Formula: (Earned Runs / Innings Pitched) * 9
     *
     * ERA measures the average number of earned runs a pitching staff allows per nine
     * innings (a complete game). This is baseball's primary pitching metric, with lower
     * values indicating better performance. Only earned runs (not unearned runs from
     * defensive errors) are counted, making it a true measure of pitching quality.
     *
     * Expected Range: 3.50-5.00 team ERA (MLB)
     *
     * @param  array<int, \App\Models\MLB\TeamStat>  $teamStats  Team statistics records
     * @return float Team ERA (e.g., 4.25)
     */
    protected function calculateTeamEra(array $teamStats): float
    {
        $totalEarnedRuns = 0;
        $totalInningsPitched = 0;

        foreach ($teamStats as $stat) {
            $totalEarnedRuns += $stat->earned_runs ?? 0;
            $totalInningsPitched += $stat->innings_pitched ?? 0;
        }

        if ($totalInningsPitched == 0) {
            return 0;
        }

        // ERA = (Earned Runs / Innings Pitched) * 9
        return ($totalEarnedRuns / $totalInningsPitched) * 9;
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
