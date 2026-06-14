<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Services\MetricValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function execute(Model $team, int $season, int|string|null $seasonType = null): ?Model
    {
        $games = $this->getCompletedGamesForTeam($team, $season, $this->sportCode(), $seasonType);
        $resolvedSeasonType = $this->resolveMetricSeasonType($games, $seasonType);

        if ($games->isEmpty()) {
            if ($this->shouldLogNoGames()) {
                Log::info('No completed games found for team', [
                    'team_id' => $team->id,
                    'team_name' => $this->teamDisplayName($team),
                    'season' => $season,
                    'season_type' => $resolvedSeasonType,
                    'sport' => $this->sportKey(),
                ]);
            }

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));
        if (empty($teamStats)) {
            return null;
        }

        if (count($teamStats) !== $games->count() || count($opponentStats) !== $games->count()) {
            Log::warning('Skipping professional basketball team metrics because completed game stats are incomplete', [
                'team_id' => $team->id,
                'team_name' => $this->teamDisplayName($team),
                'season' => $season,
                'season_type' => $resolvedSeasonType,
                'sport' => $this->sportKey(),
                'completed_games' => $games->count(),
                'team_stat_games' => count($teamStats),
                'opponent_stat_games' => count($opponentStats),
            ]);

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
        $injuryAdjustedTotalAdjustment = $this->calculateInjuryAdjustedTotalAdjustment($team, $this->sportKey());
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
                'season_type' => $resolvedSeasonType,
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
                'season_type' => $resolvedSeasonType,
            ]);
        }

        $metricModel = $this->teamMetricModelClass();
        $metricTable = (new $metricModel)->getTable();

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
            'injury_total_adjustment' => $injuryAdjustedTotalAdjustment,
            'rest_travel_fatigue' => $restTravelFatigue,
            'calculation_date' => now()->toDateString(),
        ];

        if (Schema::hasColumn($metricTable, 'season_type')) {
            $payload['season_type'] = $resolvedSeasonType;
        }

        if ($this->includesTrueEpaMetrics()) {
            $payload = [
                ...$payload,
                'offensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['offensive_true_epa_per_play'], 3),
                'defensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['defensive_true_epa_per_play'], 3),
                'net_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['net_true_epa_per_play'], 3),
            ];
        }

        $identity = [
            'team_id' => $team->id,
            'season' => $season,
        ];

        if (Schema::hasColumn($metricTable, 'season_type')) {
            $identity['season_type'] = $resolvedSeasonType;
        }

        return $metricModel::updateOrCreate(
            $identity,
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

    public function executeForAllTeams(int $season, int|string|null $seasonType = null): int
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::all();
        $calculated = 0;

        foreach ($teams as $team) {
            $metric = $this->execute($team, $season, $seasonType);
            if ($metric) {
                $calculated++;
            }
        }

        return $calculated;
    }

    protected function resolveMetricSeasonType(Collection $games, int|string|null $seasonType): string
    {
        if ($seasonType !== null && $seasonType !== '') {
            return (string) collect($this->resolveSeasonTypeCandidates($this->sportKey(), $seasonType))
                ->first(fn ($candidate) => is_numeric($candidate) || ctype_digit((string) $candidate), (string) $seasonType);
        }

        $resolved = $games
            ->pluck('season_type')
            ->filter(fn ($type) => $type !== null && $type !== '')
            ->map(fn ($type) => (string) $type)
            ->unique()
            ->values();

        return $resolved->count() === 1
            ? (string) $resolved->first()
            : (string) config($this->configPrefix().'.season.types.regular', 2);
    }
}
