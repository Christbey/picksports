<?php

namespace App\Concerns;

use App\Services\PlayerStats\NbaPlayerEpaCalculator;
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
        string $sport
    ): Collection {
        $gameModel = "App\\Models\\{$sport}\\Game";
        $sportSlug = strtolower($sport);

        return $gameModel::query()
            ->where('season', $season)
            ->where('status', config("{$sportSlug}.statuses.final"))
            ->when(
                config("{$sportSlug}.season.analytics_types"),
                fn ($query, $types) => $query->whereIn('season_type', $types)
            )
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats', 'homeTeam', 'awayTeam'])
            ->get();
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
            $teamScore = (int) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
            $opponentScore = (int) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));

            if ($teamScore > $opponentScore) {
                $wins++;
            } elseif ($teamScore < $opponentScore) {
                $losses++;
            }
        }

        return ['wins' => $wins, 'losses' => $losses];
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
        $injuryTable = "{$sportKey}_player_injuries";

        $baseline = $baseRating ?? (float) ($team->elo_rating ?? 1500.0);
        if (! Schema::hasTable($injuryTable)) {
            return round($baseline, $precision);
        }

        $injuries = DB::table($injuryTable)
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->get(['player_id', 'status']);

        if ($injuries->isEmpty()) {
            return round($baseline, $precision);
        }

        $out = 0.0;
        $questionable = 0.0;

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucketForMetrics((string) ($injury->status ?? ''));
            if ($bucket === null) {
                continue;
            }

            $impact = $this->injuryImpactMultiplierForMetrics($sportKey, (int) ($injury->player_id ?? 0));
            if ($bucket === 'out') {
                $out += $impact;
            } else {
                $questionable += $impact;
            }
        }

        $outPenalty = (float) (config("{$sportKey}.metrics.injury_out_rating_penalty") ?? 18.0);
        $questionablePenalty = (float) (config("{$sportKey}.metrics.injury_questionable_rating_penalty") ?? 7.0);
        $adjusted = $baseline - (($out * $outPenalty) + ($questionable * $questionablePenalty));

        return round($adjusted, $precision);
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
