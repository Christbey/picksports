<?php

namespace App\Concerns;

use App\Models\MLB\Game as MlbGame;
use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use App\Support\MLB\MlbGameScoreResolver;
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

            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $teamStat = $this->statForTeamSide($game->teamStats, (int) $team->id, $isHome ? 'home' : 'away');
            $opponentStat = $this->statForTeamSide($game->teamStats, (int) $opponentId, $isHome ? 'away' : 'home');

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

    protected function statForTeamSide(Collection $stats, int $teamId, string $teamType): ?Model
    {
        $stat = $stats->firstWhere('team_id', $teamId);
        if ($stat) {
            return $stat;
        }

        return $stats->first(function ($stat) use ($teamId, $teamType) {
            $statTeamId = $stat->team_id ?? null;
            if ($statTeamId !== null && (int) $statTeamId !== $teamId) {
                return false;
            }

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
        if ($game instanceof MlbGame) {
            $resolved = app(MlbGameScoreResolver::class)->forTeam($game, (int) $team->getKey());

            return [
                (float) ($resolved['team'] ?? 0),
                (float) ($resolved['opponent'] ?? 0),
            ];
        }

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

        $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
        $teamStat = $this->statForTeamSide($game->teamStats, (int) $team->id, $isHome ? 'home' : 'away');
        $opponentStat = $this->statForTeamSide($game->teamStats, (int) $opponentId, $isHome ? 'away' : 'home');

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
            [$teamScore, $oppScore] = $this->resolvedGameScoreForTeam($game, $team, $isHome);
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
        $priorVenue = null;
        $gameDates = [];
        $densityWindows = 0;

        foreach ($recentGames as $game) {
            if (! $game->game_date) {
                continue;
            }

            $date = $game->game_date->copy()->startOfDay();
            $gameDates[] = $date;

            $isAway = (int) $game->away_team_id === (int) $team->id;
            $fatigue += $isAway ? 0.30 : 0.05;

            if ($priorDate !== null) {
                $days = $priorDate->diffInDays($date);
                if ($days <= 1) {
                    $fatigue += 1.10;
                } elseif ($days === 2) {
                    $fatigue += 0.45;
                } elseif ($days === 3) {
                    $fatigue += 0.15;
                } elseif ($days >= 4) {
                    $fatigue -= 0.35;
                }
            }

            $venue = $this->gameVenueSignature($game);
            if ($priorVenue !== null && $venue !== null && $venue !== $priorVenue) {
                $fatigue += $isAway ? 0.65 : 0.35;
            }

            $priorDate = $date;
            $priorVenue = $venue ?? $priorVenue;
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
                $densityWindows++;
            }
        }

        $fatigue += min(1.8, $densityWindows * 0.30);

        $fatigue = max(0.0, min(10.0, $fatigue));

        return round($fatigue, $precision);
    }

    protected function gameVenueSignature(Model $game): ?string
    {
        $parts = array_filter([
            $game->getAttribute('venue_city'),
            $game->getAttribute('venue_state'),
            $game->getAttribute('venue_name'),
        ], fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');

        if ($parts === []) {
            return null;
        }

        return strtolower(implode('|', array_map(fn (mixed $value): string => trim((string) $value), $parts)));
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
        if ($sport === 'mlb') {
            return $this->mlbInjuryImpactMultiplierForMetrics($playerId);
        }

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

    protected function mlbInjuryImpactMultiplierForMetrics(int $playerId): float
    {
        if ($playerId <= 0 || ! Schema::hasTable('mlb_player_stats')) {
            return 1.0;
        }

        $rows = DB::table('mlb_player_stats')
            ->where('player_id', $playerId)
            ->orderByDesc('game_id')
            ->limit(16)
            ->get([
                'stat_type',
                'at_bats',
                'runs',
                'hits',
                'doubles',
                'triples',
                'home_runs',
                'rbis',
                'walks',
                'stolen_bases',
                'innings_pitched',
                'hits_allowed',
                'earned_runs',
                'walks_allowed',
                'strikeouts_pitched',
                'home_runs_allowed',
            ]);

        if ($rows->isEmpty()) {
            return 1.0;
        }

        $battingMultiplier = $this->mlbBattingInjuryMultiplier($rows);
        $pitchingMultiplier = $this->mlbPitchingInjuryMultiplier($rows);
        $multiplier = max($battingMultiplier, $pitchingMultiplier, 1.0);

        return round(max(0.5, min(2.5, $multiplier)), 2);
    }

    protected function mlbBattingInjuryMultiplier(\Illuminate\Support\Collection $rows): float
    {
        $totals = [
            'ab' => 0.0,
            'hits' => 0.0,
            'doubles' => 0.0,
            'triples' => 0.0,
            'hr' => 0.0,
            'walks' => 0.0,
            'runs' => 0.0,
            'rbi' => 0.0,
            'sb' => 0.0,
            'games' => 0.0,
        ];

        foreach ($rows as $row) {
            if (($row->stat_type ?? null) !== 'batting') {
                continue;
            }

            $totals['ab'] += (float) ($row->at_bats ?? 0);
            $totals['hits'] += (float) ($row->hits ?? 0);
            $totals['doubles'] += (float) ($row->doubles ?? 0);
            $totals['triples'] += (float) ($row->triples ?? 0);
            $totals['hr'] += (float) ($row->home_runs ?? 0);
            $totals['walks'] += (float) ($row->walks ?? 0);
            $totals['runs'] += (float) ($row->runs ?? 0);
            $totals['rbi'] += (float) ($row->rbis ?? 0);
            $totals['sb'] += (float) ($row->stolen_bases ?? 0);
            $totals['games']++;
        }

        if ($totals['games'] <= 0 || ($totals['ab'] + $totals['walks']) < 8) {
            return 1.0;
        }

        $singles = max(0.0, $totals['hits'] - $totals['doubles'] - $totals['triples'] - $totals['hr']);
        $totalBases = $singles + (2 * $totals['doubles']) + (3 * $totals['triples']) + (4 * $totals['hr']);
        $obp = ($totals['ab'] + $totals['walks']) > 0
            ? (($totals['hits'] + $totals['walks']) / ($totals['ab'] + $totals['walks']))
            : 0.0;
        $slg = $totals['ab'] > 0 ? ($totalBases / $totals['ab']) : 0.0;
        $ops = $obp + $slg;
        $homeRunsPerGame = $totals['hr'] / max(1.0, $totals['games']);
        $runProductionPerGame = ($totals['runs'] + $totals['rbi']) / max(1.0, $totals['games']);
        $stolenBasesPerGame = $totals['sb'] / max(1.0, $totals['games']);

        $score = (($ops - 0.720) * 1.75)
            + (($homeRunsPerGame - 0.12) * 0.75)
            + (($runProductionPerGame - 0.85) * 0.20)
            + (($stolenBasesPerGame - 0.08) * 0.15);

        return 1.0 + max(-0.5, min(1.5, $score));
    }

    protected function mlbPitchingInjuryMultiplier(\Illuminate\Support\Collection $rows): float
    {
        $totals = [
            'ip' => 0.0,
            'hits_allowed' => 0.0,
            'earned_runs' => 0.0,
            'walks_allowed' => 0.0,
            'strikeouts' => 0.0,
            'home_runs_allowed' => 0.0,
        ];

        foreach ($rows as $row) {
            if (($row->stat_type ?? null) !== 'pitching') {
                continue;
            }

            $totals['ip'] += $this->normalizeInningsPitched($row->innings_pitched) ?? 0.0;
            $totals['hits_allowed'] += (float) ($row->hits_allowed ?? 0);
            $totals['earned_runs'] += (float) ($row->earned_runs ?? 0);
            $totals['walks_allowed'] += (float) ($row->walks_allowed ?? 0);
            $totals['strikeouts'] += (float) ($row->strikeouts_pitched ?? 0);
            $totals['home_runs_allowed'] += (float) ($row->home_runs_allowed ?? 0);
        }

        if ($totals['ip'] < 3.0) {
            return 1.0;
        }

        $era = ($totals['earned_runs'] / $totals['ip']) * 9;
        $whip = ($totals['hits_allowed'] + $totals['walks_allowed']) / $totals['ip'];
        $kMinusWalksPerNine = (($totals['strikeouts'] - $totals['walks_allowed']) / $totals['ip']) * 9;
        $homeRunsAllowedPerNine = ($totals['home_runs_allowed'] / $totals['ip']) * 9;

        $score = ((4.20 - $era) * 0.12)
            + ((1.30 - $whip) * 0.55)
            + (($kMinusWalksPerNine - 5.20) * 0.06)
            + ((1.10 - $homeRunsAllowedPerNine) * 0.05);

        return 1.0 + max(-0.5, min(1.5, $score));
    }
}
