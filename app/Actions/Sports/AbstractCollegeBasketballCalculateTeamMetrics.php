<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Services\MetricValidator;
use App\Services\OpponentAdjustmentCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class AbstractCollegeBasketballCalculateTeamMetrics
{
    use CalculatesTeamTrueEpaFromPlays;
    use FiltersTeamGames;

    /**
     * @return class-string<Model>
     */
    abstract protected function teamModelClass(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function teamMetricModelClass(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function gameModelClass(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function playModelClass(): string;

    abstract protected function sportCode(): string;

    abstract protected function sportKey(): string;

    abstract protected function configPrefix(): string;

    abstract protected function shouldGateByMinimumGames(): bool;

    protected function teamDisplayName(Model $team): string
    {
        return trim((string) (($team->school ?? '').' '.($team->mascot ?? '')));
    }

    public function execute(Model $team, int $season): ?Model
    {
        $games = $this->getCompletedGamesForTeam($team, $season, $this->sportCode());

        $gamesPlayed = $games->count();
        if ($gamesPlayed === 0) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => $this->teamDisplayName($team),
                'season' => $season,
                'sport' => $this->sportKey(),
            ]);

            return null;
        }

        $meetsMinimum = $gamesPlayed >= config($this->configPrefix().'.metrics.minimum_games');

        extract($this->gatherTeamStatsFromGames($games, $team));
        if (empty($teamStats)) {
            return null;
        }

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

        $record = $this->calculateWinLossRecord($games, $team);

        $offensiveEfficiency = $this->calculateOffensiveEfficiency($teamStats);
        $defensiveEfficiency = $this->calculateDefensiveEfficiency($opponentStats);
        $netRating = $offensiveEfficiency - $defensiveEfficiency;
        $tempo = $this->calculateTempo($teamStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);
        $recentFormRating = $this->calculateRecentFormRating($games, $team);
        $injuryAdjustedTeamRating = $this->calculateInjuryAdjustedTeamRating($team, $this->sportKey(), (float) ($team->elo_rating ?? 1500));
        $restTravelFatigue = $this->calculateRestTravelFatigue($games, $team);
        $trueEpaMetrics = $this->calculateTeamTrueEpaMetrics($this->playModelClass(), (int) $team->id, $games);
        $rollingMetrics = $this->calculateRollingMetrics($teamStats, $opponentStats);
        $homeMetrics = $this->calculateHomeAwayMetrics($homeTeamStats, $homeOpponentStats);
        $awayMetrics = $this->calculateHomeAwayMetrics($awayTeamStats, $awayOpponentStats);

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => $this->teamDisplayName($team),
            'season' => $season,
            'sport' => $this->sportKey(),
            'games_count' => $gamesPlayed,
            'offensive_efficiency' => $this->metricValue(round($offensiveEfficiency, 1), $meetsMinimum),
            'defensive_efficiency' => $this->metricValue(round($defensiveEfficiency, 1), $meetsMinimum),
            'net_rating' => $this->metricValue(round($netRating, 1), $meetsMinimum),
        ]);

        if ($meetsMinimum) {
            $validator = new MetricValidator;
            $validator->validate([
                'offensive_efficiency' => $offensiveEfficiency,
                'defensive_efficiency' => $defensiveEfficiency,
                'tempo' => $tempo,
            ], $this->sportKey(), [
                'team_id' => $team->id,
                'team_name' => $this->teamDisplayName($team),
                'season' => $season,
            ]);
        }

        $metricModel = $this->teamMetricModelClass();

        return $metricModel::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            [
                'wins' => $record['wins'],
                'losses' => $record['losses'],
                'offensive_efficiency' => $this->metricValue(round($offensiveEfficiency, 1), $meetsMinimum),
                'defensive_efficiency' => $this->metricValue(round($defensiveEfficiency, 1), $meetsMinimum),
                'net_rating' => $this->metricValue(round($netRating, 1), $meetsMinimum),
                'tempo' => $this->metricValue(round($tempo, 1), $meetsMinimum),
                'strength_of_schedule' => $this->metricValue(round($strengthOfSchedule, 3), $meetsMinimum),
                'recent_form_rating' => $recentFormRating,
                'injury_adjusted_team_rating' => $injuryAdjustedTeamRating,
                'rest_travel_fatigue' => $restTravelFatigue,
                'games_played' => $gamesPlayed,
                'meets_minimum' => $meetsMinimum,
                'possession_coefficient' => config($this->configPrefix().'.metrics.possession_coefficient'),
                'rolling_offensive_efficiency' => $this->metricValue($rollingMetrics['offensive_efficiency'], $meetsMinimum),
                'rolling_defensive_efficiency' => $this->metricValue($rollingMetrics['defensive_efficiency'], $meetsMinimum),
                'rolling_net_rating' => $this->metricValue($rollingMetrics['net_rating'], $meetsMinimum),
                'rolling_tempo' => $this->metricValue($rollingMetrics['tempo'], $meetsMinimum),
                'rolling_games_count' => $rollingMetrics['games_count'],
                'home_offensive_efficiency' => $this->metricValue($homeMetrics['offensive_efficiency'], $meetsMinimum),
                'home_defensive_efficiency' => $this->metricValue($homeMetrics['defensive_efficiency'], $meetsMinimum),
                'away_offensive_efficiency' => $this->metricValue($awayMetrics['offensive_efficiency'], $meetsMinimum),
                'away_defensive_efficiency' => $this->metricValue($awayMetrics['defensive_efficiency'], $meetsMinimum),
                'home_games' => $homeMetrics['games_count'],
                'away_games' => $awayMetrics['games_count'],
                'offensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['offensive_true_epa_per_play'], 3),
                'defensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['defensive_true_epa_per_play'], 3),
                'net_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['net_true_epa_per_play'], 3),
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

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

        return ($totalPoints / $totalPossessions) * 100;
    }

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

        return ($totalPoints / $totalPossessions) * 100;
    }

    protected function calculateTempo(array $teamStats): float
    {
        $totalPossessions = 0;
        $gamesCount = count($teamStats);

        if ($gamesCount == 0) {
            return 0;
        }

        foreach ($teamStats as $stat) {
            $totalPossessions += $stat->possessions ?? $this->estimatePossessions($stat);
        }

        return $totalPossessions / $gamesCount;
    }

    protected function estimatePossessions(Model $stat): float
    {
        $fga = $stat->field_goals_attempted ?? 0;
        $orb = $stat->offensive_rebounds ?? 0;
        $to = $stat->turnovers ?? 0;
        $fta = $stat->free_throws_attempted ?? 0;

        return $fga - $orb + $to + (config($this->configPrefix().'.metrics.possession_coefficient') * $fta);
    }

    protected function calculateRollingMetrics(array $teamStats, array $opponentStats): array
    {
        $rollingTeamStats = array_slice($teamStats, -config($this->configPrefix().'.metrics.rolling_window_size'));
        $rollingOpponentStats = array_slice($opponentStats, -config($this->configPrefix().'.metrics.rolling_window_size'));
        $gamesCount = count($rollingTeamStats);

        if ($gamesCount == 0) {
            return [
                'offensive_efficiency' => null,
                'defensive_efficiency' => null,
                'net_rating' => null,
                'tempo' => null,
                'games_count' => 0,
            ];
        }

        $offensiveEfficiency = $this->calculateOffensiveEfficiency($rollingTeamStats);
        $defensiveEfficiency = $this->calculateDefensiveEfficiency($rollingOpponentStats);

        return [
            'offensive_efficiency' => round($offensiveEfficiency, 1),
            'defensive_efficiency' => round($defensiveEfficiency, 1),
            'net_rating' => round($offensiveEfficiency - $defensiveEfficiency, 1),
            'tempo' => round($this->calculateTempo($rollingTeamStats), 1),
            'games_count' => $gamesCount,
        ];
    }

    protected function calculateHomeAwayMetrics(array $teamStats, array $opponentStats): array
    {
        $gamesCount = count($teamStats);

        if ($gamesCount == 0) {
            return [
                'offensive_efficiency' => null,
                'defensive_efficiency' => null,
                'games_count' => 0,
            ];
        }

        return [
            'offensive_efficiency' => round($this->calculateOffensiveEfficiency($teamStats), 1),
            'defensive_efficiency' => round($this->calculateDefensiveEfficiency($opponentStats), 1),
            'games_count' => $gamesCount,
        ];
    }

    public function executeForAllTeams(int $season): int
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::all();
        $calculated = 0;

        foreach ($teams as $team) {
            $metric = $this->execute($team, $season);
            if ($metric) {
                $calculated++;
            }
        }

        $this->calculateOpponentAdjustments($season);

        return $calculated;
    }

    protected function calculateOpponentAdjustments(int $season): void
    {
        $metricModel = $this->teamMetricModelClass();
        $gameModel = $this->gameModelClass();

        $metrics = $metricModel::query()
            ->where('season', $season)
            ->where('meets_minimum', true)
            ->with('team')
            ->get();

        if ($metrics->isEmpty()) {
            return;
        }

        $games = $gameModel::query()
            ->where('season', $season)
            ->where('status', config($this->configPrefix().'.statuses.final'))
            ->with(['teamStats'])
            ->get();

        $calculator = new OpponentAdjustmentCalculator(
            $this->sportKey(),
            $season,
            fn ($stat) => $this->estimatePossessions($stat)
        );

        $calculator->calculate($metrics, $games);
        $calculator->setIterationCount($metrics, config($this->configPrefix().'.metrics.max_adjustment_iterations'));
    }

    protected function metricValue(float|int|null $value, bool $meetsMinimum): float|int|null
    {
        if (! $this->shouldGateByMinimumGames()) {
            return $value;
        }

        return $meetsMinimum ? $value : null;
    }
}
