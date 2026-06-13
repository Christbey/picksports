<?php

namespace App\Concerns;

use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use App\Support\MlbRegularSeasonWindow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait FiltersTeamGames
{
    /**
     * Get completed games for a team in a specific season.
     */
    protected function getCompletedGamesForTeam(
        Model $team,
        int $season,
        string $sport,
        int|string|null $seasonType = null
    ): Collection {
        $gameModel = "App\\Models\\{$sport}\\Game";
        $sportSlug = strtolower($sport);
        $analyticsTypeCandidates = $this->resolveAnalyticsSeasonTypeCandidates($sportSlug);
        $seasonTypeCandidates = $seasonType !== null
            ? $this->resolveSeasonTypeCandidates($sportSlug, $seasonType)
            : $analyticsTypeCandidates;

        return $gameModel::query()
            ->where('season', $season)
            ->where('status', config("{$sportSlug}.statuses.final"))
            ->when(
                $seasonTypeCandidates !== [],
                fn ($query) => $query->whereIn('season_type', $seasonTypeCandidates)
            )
            ->when(
                $sportSlug === 'mlb'
                    && ! in_array((string) $seasonType, [(string) config('mlb.season.types.spring_training', 1), 'spring_training'], true)
                    && ($openerDate = MlbRegularSeasonWindow::openerDate($season)) !== null,
                fn ($query) => $query->whereDate('game_date', '>=', $openerDate)
            )
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats', 'homeTeam', 'awayTeam'])
            ->get();
    }

    /**
     * Resolve season_type filter candidates to support numeric and label-stored values.
     *
     * @return array<int, int|string>
     */
    protected function resolveAnalyticsSeasonTypeCandidates(string $sportSlug): array
    {
        $configuredTypes = config("{$sportSlug}.season.analytics_types");
        if (! is_array($configuredTypes) || $configuredTypes === []) {
            return [];
        }

        $candidates = [];

        foreach ($configuredTypes as $type) {
            $candidates = [...$candidates, ...$this->resolveSeasonTypeCandidates($sportSlug, $type)];
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<int, int|string>
     */
    protected function resolveSeasonTypeCandidates(string $sportSlug, int|string $seasonType): array
    {
        $configuredType = $seasonType;

        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [];

        if ($configuredType === null || $configuredType === '') {
            return [];
        }

        $candidates[] = $configuredType;
        $candidates[] = (string) $configuredType;

        if (is_string($configuredType) && isset($typeNames[$configuredType])) {
            $candidates[] = $typeNames[$configuredType];
        }

        if (is_string($configuredType) && isset($typesByKey[$configuredType])) {
            $resolved = $typesByKey[$configuredType];
            $candidates[] = $resolved;
            $candidates[] = (string) $resolved;
        }

        if (is_numeric($configuredType)) {
            $code = (int) $configuredType;
            $matchedKey = array_search($code, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        $normalized = array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));

        return $normalized;
    }

    /**
     * Gather team and opponent stats from games.
     */
    protected function gatherTeamStatsFromGames(
        Collection $games,
        Model $team
    ): array {
        $teamStats = [];
        $opponentStats = [];
        $opponentElos = [];

        // Batch-load per-game ELO ratings for accurate SOS calculation
        $perGameElos = $this->loadPerGameEloRatings($games, $team);

        foreach ($games as $game) {
            $isHome = $game->home_team_id === $team->id;

            $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
            if (! $teamStat) {
                $teamStat = $this->statByTeamType($game->teamStats, $isHome ? 'home' : 'away');
            }
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);
            if (! $opponentStat) {
                $opponentStat = $this->statByTeamType($game->teamStats, $isHome ? 'away' : 'home');
            }

            if ($teamStat) {
                $teamStats[] = $teamStat;
            }

            if ($opponentStat) {
                $opponentStats[] = $opponentStat;
            }

            // Use per-game ELO (pre-game rating) when available, fall back to current ELO
            $eloKey = $game->id.'-'.$opponentId;
            if (isset($perGameElos[$eloKey])) {
                $opponentElos[] = $perGameElos[$eloKey];
            } else {
                $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
                if ($opponent && $opponent->elo_rating) {
                    $opponentElos[] = $opponent->elo_rating;
                }
            }
        }

        return compact('teamStats', 'opponentStats', 'opponentElos');
    }

    protected function statByTeamType(Collection $stats, string $teamType): ?Model
    {
        return $stats->first(function ($stat) use ($teamType) {
            return strtolower((string) ($stat->team_type ?? '')) === $teamType;
        });
    }

    /**
     * Load per-game ELO ratings for opponents, returning pre-game ELO values.
     *
     * @return array<string, float> Keyed by "game_id-team_id" with pre-game ELO values
     */
    protected function loadPerGameEloRatings(Collection $games, Model $team): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $sport = class_basename((new \ReflectionClass($team))->getNamespaceName());
        $eloRatingModel = "App\\Models\\{$sport}\\EloRating";

        if (! class_exists($eloRatingModel)) {
            return [];
        }

        $gameIds = $games->pluck('id')->toArray();

        return $eloRatingModel::query()
            ->whereIn('game_id', $gameIds)
            ->where('team_id', '!=', $team->id)
            ->get()
            ->mapWithKeys(function ($record) {
                // Pre-game ELO = post-game ELO minus the change from this game
                $preGameElo = (float) $record->elo_rating - (float) $record->elo_change;

                return [$record->game_id.'-'.$record->team_id => $preGameElo];
            })
            ->toArray();
    }

    /**
     * Calculate strength of schedule from opponent ELOs.
     */
    protected function calculateStrengthOfSchedule(array $opponentElos, int $precision = 3): ?float
    {
        if (empty($opponentElos)) {
            return null;
        }

        return round(array_sum($opponentElos) / count($opponentElos), $precision);
    }

    /**
     * @return array{wins:int,losses:int}
     */
    protected function calculateWinLossRecord(Collection $games, Model $team): array
    {
        $wins = 0;
        $losses = 0;

        foreach ($games as $game) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            [$teamScore, $opponentScore] = $this->resolvedGameScoreForTeam($game, $team, $isHome);

            if ($teamScore > $opponentScore) {
                $wins++;
            } elseif ($teamScore < $opponentScore) {
                $losses++;
            }
        }

        return ['wins' => $wins, 'losses' => $losses];
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function resolvedGameScoreForTeam(Model $game, Model $team, bool $isHome): array
    {
        $gameTeamScore = $isHome ? $game->home_score : $game->away_score;
        $gameOpponentScore = $isHome ? $game->away_score : $game->home_score;

        if ($gameTeamScore !== null || $gameOpponentScore !== null) {
            $teamScore = (float) ($gameTeamScore ?? 0);
            $opponentScore = (float) ($gameOpponentScore ?? 0);

            if ($teamScore !== 0.0 || $opponentScore !== 0.0) {
                return [$teamScore, $opponentScore];
            }
        }

        if (! method_exists($game, 'relationLoaded') || ! $game->relationLoaded('teamStats')) {
            return [(float) ($gameTeamScore ?? 0), (float) ($gameOpponentScore ?? 0)];
        }

        $teamStat = $game->teamStats->firstWhere('team_id', $team->id)
            ?? $this->statByTeamType($game->teamStats, $isHome ? 'home' : 'away');
        $opponentStat = $this->statByTeamType($game->teamStats, $isHome ? 'away' : 'home');

        if (! $opponentStat) {
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);
        }

        $teamStatScore = $this->scoreFromTeamStat($teamStat);
        $opponentStatScore = $this->scoreFromTeamStat($opponentStat);

        if ($teamStatScore !== null && $opponentStatScore !== null) {
            return [$teamStatScore, $opponentStatScore];
        }

        return [(float) ($gameTeamScore ?? 0), (float) ($gameOpponentScore ?? 0)];
    }

    protected function scoreFromTeamStat(?Model $stat): ?float
    {
        if (! $stat) {
            return null;
        }

        foreach (['runs', 'points', 'score'] as $column) {
            if ($stat->getAttribute($column) !== null) {
                return (float) $stat->getAttribute($column);
            }
        }

        return null;
    }

    /**
     * Recent form rating based on weighted scoring margin over latest games.
     */
    protected function calculateRecentFormRating(Collection $games, Model $team, int $window = 5, int $precision = 3): ?float
    {
        if ($games->isEmpty()) {
            return null;
        }

        $recentGames = $games
            ->sortByDesc('game_date')
            ->take(max(1, $window))
            ->values();

        $weightedMargin = 0.0;
        $totalWeight = 0.0;
        $decay = 0.85;

        foreach ($recentGames as $index => $game) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $teamScore = (float) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
            $oppScore = (float) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));
            $margin = $teamScore - $oppScore;
            $weight = pow($decay, $index);

            $weightedMargin += $margin * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            return null;
        }

        return round($weightedMargin / $totalWeight, $precision);
    }

    /**
     * Team fatigue score from recent scheduling and travel pattern.
     * Higher values indicate more fatigue.
     */
    protected function calculateRestTravelFatigue(Collection $games, Model $team, int $window = 10, int $precision = 3): ?float
    {
        if ($games->isEmpty()) {
            return null;
        }

        $recentGames = $games
            ->sortByDesc('game_date')
            ->take(max(3, $window))
            ->sortBy('game_date')
            ->values();

        if ($recentGames->isEmpty()) {
            return null;
        }

        $fatigue = 0.0;
        $priorDate = null;
        $gameDates = [];

        foreach ($recentGames as $game) {
            if (! $game->game_date) {
                continue;
            }

            $date = $game->game_date->copy()->startOfDay();
            $gameDates[] = $date;

            $isAway = (int) $game->away_team_id === (int) $team->id;
            $fatigue += $isAway ? 0.35 : 0.10;

            if ($priorDate !== null) {
                $days = $priorDate->diffInDays($date);
                if ($days <= 1) {
                    $fatigue += 1.25;
                } elseif ($days === 2) {
                    $fatigue += 0.65;
                } elseif ($days >= 4) {
                    $fatigue -= 0.25;
                }
            }

            $priorDate = $date;
        }

        // Density boost for compressed schedule (3+ games in 4-day windows).
        $dateCount = count($gameDates);
        for ($i = 0; $i < $dateCount; $i++) {
            $windowCount = 1;
            for ($j = $i + 1; $j < $dateCount; $j++) {
                if ($gameDates[$i]->diffInDays($gameDates[$j]) <= 3) {
                    $windowCount++;
                } else {
                    break;
                }
            }
            if ($windowCount >= 3) {
                $fatigue += 0.60;
            }
        }

        $fatigue = max(0.0, min(10.0, $fatigue));

        return round($fatigue, $precision);
    }

    /**
     * Injury-adjusted team rating on a shared Elo-like scale.
     */
    protected function calculateInjuryAdjustedTeamRating(Model $team, string $sport, ?float $baseRating = null, int $precision = 3): ?float
    {
        $sportKey = strtolower($sport);
        $baseline = $baseRating ?? (float) ($team->elo_rating ?? 1500.0);
        $counts = $this->injuryMetricCountsForTeam($team, $sportKey);

        if ($counts === null) {
            return round($baseline, $precision);
        }

        $outPenalty = (float) (config("{$sportKey}.metrics.injury_out_rating_penalty") ?? 18.0);
        $questionablePenalty = (float) (config("{$sportKey}.metrics.injury_questionable_rating_penalty") ?? 7.0);
        $adjusted = $baseline - (($counts['out'] * $outPenalty) + ($counts['questionable'] * $questionablePenalty));

        return round($adjusted, $precision);
    }

    protected function calculateInjuryAdjustedTotalAdjustment(Model $team, string $sport, int $precision = 3): ?float
    {
        $sportKey = strtolower($sport);
        $counts = $this->injuryMetricCountsForTeam($team, $sportKey);

        if ($counts === null) {
            return null;
        }

        $outPenalty = (float) (config("{$sportKey}.prediction.injury_out_total_penalty")
            ?? config("{$sportKey}.predictions.injury_out_total_penalty")
            ?? 0.40);
        $questionablePenalty = (float) (config("{$sportKey}.prediction.injury_questionable_total_penalty")
            ?? config("{$sportKey}.predictions.injury_questionable_total_penalty")
            ?? 0.15);

        $adjustment = -(($counts['out'] * $outPenalty) + ($counts['questionable'] * $questionablePenalty));

        return round($adjustment, $precision);
    }

    /**
     * @return array{out:float,questionable:float}|null
     */
    protected function injuryMetricCountsForTeam(Model $team, string $sportKey): ?array
    {
        $injuryTable = "{$sportKey}_player_injuries";
        if (! Schema::hasTable($injuryTable)) {
            return null;
        }

        $injuries = DB::table($injuryTable)
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->get(['player_id', 'status']);

        if ($injuries->isEmpty()) {
            return ['out' => 0.0, 'questionable' => 0.0];
        }

        $counts = ['out' => 0.0, 'questionable' => 0.0];

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucketForMetrics((string) ($injury->status ?? ''));
            if ($bucket === null) {
                continue;
            }

            $impact = $this->injuryImpactMultiplierForMetrics($sportKey, (int) ($injury->player_id ?? 0));
            $counts[$bucket] += $impact;
        }

        $counts['out'] = round($counts['out'], 2);
        $counts['questionable'] = round($counts['questionable'], 2);

        return $counts;
    }

    protected function injuryStatusBucketForMetrics(string $status): ?string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'out')
            || str_contains($normalized, 'doubtful')
            || str_contains($normalized, 'inactive')
            || str_contains($normalized, 'suspended')
            || str_contains($normalized, 'ir')
        ) {
            return 'out';
        }

        if (
            str_contains($normalized, 'questionable')
            || str_contains($normalized, 'game-time')
            || str_contains($normalized, 'gtd')
            || str_contains($normalized, 'probable')
            || str_contains($normalized, 'day-to-day')
        ) {
            return 'questionable';
        }

        return null;
    }

    protected function injuryImpactMultiplierForMetrics(string $sport, int $playerId): float
    {
        if (! in_array($sport, ['nba', 'wnba', 'cbb', 'wcbb'], true) || $playerId <= 0) {
            return 1.0;
        }

        $statTable = "{$sport}_player_stats";
        if (! Schema::hasTable($statTable)) {
            return 1.0;
        }

        $rows = DB::table($statTable)
            ->where('player_id', $playerId)
            ->orderByDesc('game_id')
            ->limit(8)
            ->get([
                'points',
                'assists',
                'rebounds_total',
                'steals',
                'blocks',
                'turnovers',
                'field_goals_made',
                'field_goals_attempted',
                'free_throws_made',
                'free_throws_attempted',
            ]);

        if ($rows->isEmpty()) {
            return 1.0;
        }

        $calculator = app(NbaPlayerEpaCalculator::class);
        $profile = $sport === 'nba' ? NbaPlayerEpaCalculator::PROFILE_NBA : NbaPlayerEpaCalculator::PROFILE_CBB;
        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += $calculator->estimateFromBoxScore(
                $row->points,
                $row->assists,
                $row->rebounds_total,
                $row->steals,
                $row->blocks,
                $row->turnovers,
                $row->field_goals_made,
                $row->field_goals_attempted,
                $row->free_throws_made,
                $row->free_throws_attempted,
                $profile
            );
        }

        $avgEpa = $sum / max(1, $rows->count());
        $baseline = max(1.0, (float) (config("{$sport}.metrics.injury_epa_baseline") ?? 12.0));
        $multiplier = $avgEpa / $baseline;

        if (! is_finite($multiplier) || $multiplier <= 0) {
            return 1.0;
        }

        return round(max(0.5, min(2.0, $multiplier)), 2);
    }
}
