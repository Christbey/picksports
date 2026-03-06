<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Services\MetricValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class AbstractProfessionalBasketballCalculateTeamMetrics
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
    abstract protected function playModelClass(): string;

    abstract protected function sportCode(): string;

    abstract protected function sportKey(): string;

    abstract protected function configPrefix(): string;

    abstract protected function includesTrueEpaMetrics(): bool;

    protected function shouldLogNoGames(): bool
    {
        return false;
    }

    protected function shouldLogCalculatedMetrics(): bool
    {
        return false;
    }

    protected function shouldValidateMetrics(): bool
    {
        return false;
    }

    protected function teamDisplayName(Model $team): string
    {
        return trim((string) (($team->city ?? '').' '.($team->name ?? '')));
    }

    public function execute(Model $team, int $season): ?Model
    {
        $games = $this->getCompletedGamesForTeam($team, $season, $this->sportCode());

        if ($games->isEmpty()) {
            if ($this->shouldLogNoGames()) {
                Log::info('No completed games found for team', [
                    'team_id' => $team->id,
                    'team_name' => $this->teamDisplayName($team),
                    'season' => $season,
                    'sport' => $this->sportKey(),
                ]);
            }

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));
        if (empty($teamStats)) {
            return null;
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

        $trueEpaMetrics = [
            'offensive_true_epa_per_play' => null,
            'defensive_true_epa_per_play' => null,
            'net_true_epa_per_play' => null,
        ];

        if ($this->includesTrueEpaMetrics()) {
            $trueEpaMetrics = $this->calculateTeamTrueEpaMetrics($this->playModelClass(), (int) $team->id, $games);
        }

        if ($this->shouldLogCalculatedMetrics()) {
            Log::info('Team metrics calculated', [
                'team_id' => $team->id,
                'team_name' => $this->teamDisplayName($team),
                'season' => $season,
                'sport' => $this->sportKey(),
                'games_count' => count($teamStats),
                'offensive_efficiency' => round($offensiveEfficiency, 1),
                'defensive_efficiency' => round($defensiveEfficiency, 1),
                'net_rating' => round($netRating, 1),
            ]);
        }

        if ($this->shouldValidateMetrics()) {
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

        $payload = [
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'offensive_efficiency' => round($offensiveEfficiency, 1),
            'defensive_efficiency' => round($defensiveEfficiency, 1),
            'net_rating' => round($netRating, 1),
            'tempo' => round($tempo, 1),
            'strength_of_schedule' => round($strengthOfSchedule, 3),
            'recent_form_rating' => $recentFormRating,
            'injury_adjusted_team_rating' => $injuryAdjustedTeamRating,
            'rest_travel_fatigue' => $restTravelFatigue,
            'calculation_date' => now()->toDateString(),
        ];

        if ($this->includesTrueEpaMetrics()) {
            $payload = [
                ...$payload,
                'offensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['offensive_true_epa_per_play'], 3),
                'defensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['defensive_true_epa_per_play'], 3),
                'net_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['net_true_epa_per_play'], 3),
            ];
        }

        $metricModel = $this->teamMetricModelClass();

        return $metricModel::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            $payload
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
        $gameCount = count($teamStats);

        foreach ($teamStats as $stat) {
            $totalPossessions += $stat->possessions ?? $this->estimatePossessions($stat);
        }

        if ($gameCount == 0) {
            return 0;
        }

        return $totalPossessions / $gameCount;
    }

    protected function estimatePossessions(Model $stat): float
    {
        $fga = $stat->field_goals_attempted ?? 0;
        $orb = $stat->offensive_rebounds ?? 0;
        $to = $stat->turnovers ?? 0;
        $fta = $stat->free_throws_attempted ?? 0;

        return $fga - $orb + $to + (config($this->configPrefix().'.possession_coefficient') * $fta);
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

        return $calculated;
    }
}
