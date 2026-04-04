<?php

namespace App\Services\NFL;

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\TeamMetric;
use App\Services\Sports\FuturesOddsLookupService;

class PlayerFuturesProjectionService
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function projections(
        int $season,
        ?string $market = null,
        ?int $playerId = null,
        bool $onlyWithOdds = true,
        string $sortBy = 'edge',
        string $direction = 'desc',
        int $limit = 100,
        ?int $asOfWeek = null
    ): array {
        $markets = $this->marketDefinitions();
        $selectedMarkets = $market !== null && isset($markets[$market])
            ? [$market => $markets[$market]]
            : $markets;

        $cutoffDate = $this->cutoffDate($season, $asOfWeek);
        $aggregates = $this->seasonAggregates($season, $playerId, $asOfWeek);
        $teamSchedule = $this->teamScheduleSummary($season, $asOfWeek);
        $injuryStatuses = $this->activeInjuryStatuses($cutoffDate);
        $oddsByPlayer = $asOfWeek === null
            ? $this->futuresOddsLookup->nflPlayerSeasonTotalsBySeason($season)
            : [];
        $depthChart = $this->depthChartContext($season, $asOfWeek);
        $teamTotals = $this->teamTotalsByTeam($aggregates);
        $teamMetrics = $this->teamMetricContext($season, $cutoffDate);
        $remainingOpponents = $this->remainingOpponentsByTeam($season, $asOfWeek);

        $rows = [];

        foreach ($aggregates as $aggregate) {
            $position = strtoupper((string) ($aggregate['player']['position'] ?? ''));
            $teamId = (int) ($aggregate['player']['team']['id'] ?? 0);
            $teamScheduleSummary = $teamSchedule[$teamId] ?? [
                'games_scheduled' => (int) config('nfl.player_futures.default_regular_season_games', 18),
                'games_completed' => (int) ($aggregate['games_played'] ?? 0),
            ];
            $remainingGames = max(
                0,
                (int) $teamScheduleSummary['games_scheduled'] - (int) $teamScheduleSummary['games_completed']
            );
            $availabilityFactor = $this->availabilityFactor($injuryStatuses[(int) $aggregate['player_id']] ?? null);

            foreach ($selectedMarkets as $marketKey => $definition) {
                if (! $this->positionSupportsMarket($position, $definition)) {
                    continue;
                }

                $currentTotal = (float) ($aggregate['totals'][$definition['stat_field']] ?? 0.0);
                $gamesPlayed = max(0, (int) ($aggregate['games_played'] ?? 0));
                $depthEntry = $depthChart['by_player'][(int) $aggregate['player_id']] ?? null;
                $archetype = $this->archetype($position, $depthEntry);
                $directPerGame = $this->projectedPerGame($currentTotal, $gamesPlayed, $position, $archetype, $definition);
                $currentShare = $this->currentUsageShare(
                    teamId: $teamId,
                    statField: (string) $definition['stat_field'],
                    currentTotal: $currentTotal,
                    teamTotals: $teamTotals,
                );
                $stabilizedShare = $this->stabilizedUsageShare($currentShare, $gamesPlayed, $archetype, $definition);
                $teamOpportunityPerGame = $this->teamOpportunityPerGame(
                    teamId: $teamId,
                    statField: (string) $definition['stat_field'],
                    teamTotals: $teamTotals,
                    teamScheduleSummary: $teamScheduleSummary,
                );
                $usageSharePerGame = $teamOpportunityPerGame * $stabilizedShare;
                $projectedPerGame = $this->blendedPerGameProjection($directPerGame, $usageSharePerGame);
                $roleMultiplier = $this->roleMultiplier($archetype, $depthEntry);
                $competitionInjuryBoost = $this->competitionInjuryBoost(
                    playerId: (int) $aggregate['player_id'],
                    market: $marketKey,
                    teamEntries: $depthChart['by_team'][$teamId] ?? [],
                    injuryStatuses: $injuryStatuses,
                );
                $scheduleAdjustmentFactor = $this->scheduleAdjustmentFactor(
                    teamId: $teamId,
                    remainingOpponents: $remainingOpponents,
                    teamMetrics: $teamMetrics,
                );
                $projectedPerGame *= $roleMultiplier * $competitionInjuryBoost * $scheduleAdjustmentFactor;
                $projectedRemaining = $projectedPerGame * $remainingGames * $availabilityFactor;
                $projectedTotal = $currentTotal + $projectedRemaining;
                $stddevPerGame = $this->stddev(
                    $aggregate['logs'][$definition['stat_field']] ?? [],
                    (float) ($definition['default_stddev_per_game'] ?? 0.0)
                );
                $totalStddev = max(1.0, $stddevPerGame * sqrt(max(1.0, $remainingGames * max($availabilityFactor, 0.25))));
                $marketOdds = $oddsByPlayer[(int) $aggregate['player_id']][$marketKey] ?? null;

                if ($onlyWithOdds && $marketOdds === null) {
                    continue;
                }

                $line = is_array($marketOdds) && isset($marketOdds['line']) ? (float) $marketOdds['line'] : null;
                $overProbability = $line !== null ? $this->normalTailProbability($line, $projectedTotal, $totalStddev) : null;
                $underProbability = $overProbability !== null ? round(1 - $overProbability, 4) : null;
                $edgeOver = $overProbability !== null && isset($marketOdds['over_implied_probability'])
                    ? round($overProbability - (float) $marketOdds['over_implied_probability'], 4)
                    : null;
                $edgeUnder = $underProbability !== null && isset($marketOdds['under_implied_probability'])
                    ? round($underProbability - (float) $marketOdds['under_implied_probability'], 4)
                    : null;

                $rows[] = [
                    'player_id' => (int) $aggregate['player_id'],
                    'market' => $marketKey,
                    'market_label' => (string) $definition['label'],
                    'player' => $aggregate['player'],
                    'games_played' => $gamesPlayed,
                    'team_games_played' => (int) $teamScheduleSummary['games_completed'],
                    'team_games_scheduled' => (int) $teamScheduleSummary['games_scheduled'],
                    'remaining_games' => $remainingGames,
                    'availability_factor' => round($availabilityFactor, 2),
                    'injury_status' => $injuryStatuses[(int) $aggregate['player_id']] ?? null,
                    'archetype' => $archetype,
                    'current_usage_share' => round($currentShare, 4),
                    'stabilized_usage_share' => round($stabilizedShare, 4),
                    'team_opportunity_per_game' => round($teamOpportunityPerGame, 2),
                    'role_multiplier' => round($roleMultiplier, 3),
                    'competition_injury_boost' => round($competitionInjuryBoost, 3),
                    'schedule_adjustment_factor' => round($scheduleAdjustmentFactor, 3),
                    'current_total' => round($currentTotal, 1),
                    'projected_per_game' => round($projectedPerGame, 2),
                    'projected_remaining' => round($projectedRemaining, 1),
                    'projected_total' => round($projectedTotal, 1),
                    'projection_stddev' => round($totalStddev, 2),
                    'market_odds' => $marketOdds,
                    'over_probability' => $overProbability,
                    'under_probability' => $underProbability,
                    'edge_over_probability' => $edgeOver,
                    'edge_under_probability' => $edgeUnder,
                    'best_edge_probability' => $this->bestEdgeProbability($edgeOver, $edgeUnder),
                ];
            }
        }

        usort($rows, function (array $left, array $right) use ($sortBy, $direction): int {
            $leftValue = $this->sortValue($left, $sortBy);
            $rightValue = $this->sortValue($right, $sortBy);

            if ($leftValue === $rightValue) {
                return 0;
            }

            $comparison = $leftValue <=> $rightValue;

            return $direction === 'asc' ? $comparison : -$comparison;
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function supportedMarkets(): array
    {
        return $this->marketDefinitions();
    }

    /**
     * @return array<int, array<string, float>>
     */
    public function actualSeasonTotals(int $season): array
    {
        $aggregates = $this->seasonAggregates($season);
        $totals = [];

        foreach ($aggregates as $aggregate) {
            $totals[(int) $aggregate['player_id']] = array_map(
                static fn ($value): float => (float) $value,
                (array) ($aggregate['totals'] ?? [])
            );
        }

        return $totals;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function marketDefinitions(): array
    {
        return (array) config('nfl.player_futures.markets', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function seasonAggregates(int $season, ?int $playerId = null, ?int $asOfWeek = null): array
    {
        $rows = PlayerStat::query()
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->join('nfl_players', 'nfl_players.id', '=', 'nfl_player_stats.player_id')
            ->leftJoin('nfl_teams', 'nfl_teams.id', '=', 'nfl_players.team_id')
            ->where('nfl_games.status', config('nfl.statuses.final'))
            ->where('nfl_games.season', $season)
            ->whereIn('nfl_games.season_type', $this->regularSeasonTypeCandidates())
            ->when($asOfWeek !== null, fn ($query) => $query->where('nfl_games.week', '<=', $asOfWeek))
            ->when($playerId !== null, fn ($query) => $query->where('nfl_player_stats.player_id', $playerId))
            ->selectRaw('
                nfl_player_stats.player_id,
                COUNT(DISTINCT nfl_player_stats.game_id) as games_played,
                SUM(COALESCE(nfl_player_stats.passing_yards, 0)) as passing_yards,
                SUM(COALESCE(nfl_player_stats.passing_touchdowns, 0)) as passing_touchdowns,
                SUM(COALESCE(nfl_player_stats.rushing_yards, 0)) as rushing_yards,
                SUM(COALESCE(nfl_player_stats.rushing_touchdowns, 0)) as rushing_touchdowns,
                SUM(COALESCE(nfl_player_stats.receptions, 0)) as receptions,
                SUM(COALESCE(nfl_player_stats.receiving_yards, 0)) as receiving_yards,
                SUM(COALESCE(nfl_player_stats.receiving_touchdowns, 0)) as receiving_touchdowns,
                nfl_players.id as player_ref_id,
                nfl_players.full_name,
                nfl_players.headshot_url,
                nfl_players.position,
                nfl_players.jersey_number,
                nfl_teams.id as team_id,
                nfl_teams.name as team_name,
                nfl_teams.location as team_location,
                nfl_teams.abbreviation as team_abbreviation
            ')
            ->groupBy([
                'nfl_player_stats.player_id',
                'nfl_players.id',
                'nfl_players.full_name',
                'nfl_players.headshot_url',
                'nfl_players.position',
                'nfl_players.jersey_number',
                'nfl_teams.id',
                'nfl_teams.name',
                'nfl_teams.location',
                'nfl_teams.abbreviation',
            ])
            ->get();

        $logs = PlayerStat::query()
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->where('nfl_games.status', config('nfl.statuses.final'))
            ->where('nfl_games.season', $season)
            ->whereIn('nfl_games.season_type', $this->regularSeasonTypeCandidates())
            ->when($asOfWeek !== null, fn ($query) => $query->where('nfl_games.week', '<=', $asOfWeek))
            ->when($playerId !== null, fn ($query) => $query->where('nfl_player_stats.player_id', $playerId))
            ->get([
                'nfl_player_stats.player_id',
                'nfl_player_stats.passing_yards',
                'nfl_player_stats.passing_touchdowns',
                'nfl_player_stats.rushing_yards',
                'nfl_player_stats.rushing_touchdowns',
                'nfl_player_stats.receptions',
                'nfl_player_stats.receiving_yards',
                'nfl_player_stats.receiving_touchdowns',
            ])
            ->groupBy('player_id');

        return $rows->map(function ($row) use ($logs): array {
            $playerLogs = $logs->get($row->player_id, collect());

            return [
                'player_id' => (int) $row->player_id,
                'games_played' => (int) $row->games_played,
                'player' => [
                    'id' => (int) $row->player_ref_id,
                    'full_name' => $row->full_name,
                    'headshot_url' => $row->headshot_url,
                    'position' => $row->position,
                    'jersey_number' => $row->jersey_number,
                    'team' => $row->team_id ? [
                        'id' => (int) $row->team_id,
                        'name' => $row->team_name,
                        'display_name' => trim("{$row->team_location} {$row->team_name}"),
                        'abbreviation' => $row->team_abbreviation,
                    ] : null,
                ],
                'totals' => [
                    'passing_yards' => (float) $row->passing_yards,
                    'passing_touchdowns' => (float) $row->passing_touchdowns,
                    'rushing_yards' => (float) $row->rushing_yards,
                    'rushing_touchdowns' => (float) $row->rushing_touchdowns,
                    'receptions' => (float) $row->receptions,
                    'receiving_yards' => (float) $row->receiving_yards,
                    'receiving_touchdowns' => (float) $row->receiving_touchdowns,
                ],
                'logs' => [
                    'passing_yards' => $playerLogs->pluck('passing_yards')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'passing_touchdowns' => $playerLogs->pluck('passing_touchdowns')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'rushing_yards' => $playerLogs->pluck('rushing_yards')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'rushing_touchdowns' => $playerLogs->pluck('rushing_touchdowns')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'receptions' => $playerLogs->pluck('receptions')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'receiving_yards' => $playerLogs->pluck('receiving_yards')->map(fn ($value) => (float) ($value ?? 0))->all(),
                    'receiving_touchdowns' => $playerLogs->pluck('receiving_touchdowns')->map(fn ($value) => (float) ($value ?? 0))->all(),
                ],
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $aggregates
     * @return array<int, array<string, float>>
     */
    protected function teamTotalsByTeam(array $aggregates): array
    {
        $totals = [];

        foreach ($aggregates as $aggregate) {
            $teamId = (int) ($aggregate['player']['team']['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $totals[$teamId] ??= [];

            foreach ((array) ($aggregate['totals'] ?? []) as $field => $value) {
                $totals[$teamId][$field] = (float) ($totals[$teamId][$field] ?? 0.0) + (float) $value;
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array{games_scheduled:int,games_completed:int}>
     */
    protected function teamScheduleSummary(int $season, ?int $asOfWeek = null): array
    {
        $games = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', $this->regularSeasonTypeCandidates())
            ->get(['home_team_id', 'away_team_id', 'status', 'week']);

        $summary = [];

        foreach ($games as $game) {
            foreach ([(int) $game->home_team_id, (int) $game->away_team_id] as $teamId) {
                if ($teamId <= 0) {
                    continue;
                }

                $summary[$teamId] ??= ['games_scheduled' => 0, 'games_completed' => 0];
                $summary[$teamId]['games_scheduled']++;

                if (
                    (string) $game->status === (string) config('nfl.statuses.final')
                    && ($asOfWeek === null || (int) ($game->week ?? 0) <= $asOfWeek)
                ) {
                    $summary[$teamId]['games_completed']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    protected function activeInjuryStatuses(?string $cutoffDate = null): array
    {
        $query = PlayerInjury::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('source_updated_at');

        if ($cutoffDate === null) {
            $query->where('is_active', true);
        } else {
            $query->where(function ($injuryQuery) use ($cutoffDate): void {
                $injuryQuery->whereDate('injury_date', '<=', $cutoffDate)
                    ->where(function ($returnQuery) use ($cutoffDate): void {
                        $returnQuery->whereNull('return_date')
                            ->orWhereDate('return_date', '>=', $cutoffDate);
                    });
            });
        }

        return $query
            ->get(['player_id', 'status'])
            ->unique('player_id')
            ->mapWithKeys(fn ($injury) => [(int) $injury->player_id => (string) $injury->status])
            ->all();
    }

    /**
     * @return array{by_player:array<int, array<string, mixed>>, by_team:array<int, array<int, array<string, mixed>>>}
     */
    protected function depthChartContext(int $season, ?int $asOfWeek = null): array
    {
        $entries = DepthChartEntry::query()
            ->when(
                $asOfWeek !== null,
                fn ($query) => $query->where('season', '<', $season),
                fn ($query) => $query->where('season', '<=', $season)
            )
            ->orderByDesc('season')
            ->orderByDesc('is_starter')
            ->orderBy('depth_rank')
            ->orderBy('slot_order')
            ->get([
                'team_id',
                'player_id',
                'season',
                'position_slot_key',
                'position_code',
                'position_name',
                'position_display_name',
                'depth_rank',
                'is_starter',
            ]);

        $byPlayer = [];
        $byTeam = [];

        foreach ($entries as $entry) {
            $playerId = (int) ($entry->player_id ?? 0);
            $teamId = (int) ($entry->team_id ?? 0);
            $serialized = [
                'team_id' => $teamId,
                'player_id' => $playerId,
                'season' => (int) ($entry->season ?? $season),
                'position_code' => strtoupper((string) ($entry->position_code ?? '')),
                'position_slot_key' => strtoupper((string) ($entry->position_slot_key ?? '')),
                'position_name' => strtoupper((string) ($entry->position_name ?? $entry->position_display_name ?? '')),
                'depth_rank' => (int) ($entry->depth_rank ?? 99),
                'is_starter' => (bool) $entry->is_starter,
            ];

            if ($teamId > 0) {
                $byTeam[$teamId] ??= [];
                $byTeam[$teamId][] = $serialized;
            }

            if ($playerId <= 0 || isset($byPlayer[$playerId])) {
                continue;
            }

            $byPlayer[$playerId] = $serialized;
        }

        return [
            'by_player' => $byPlayer,
            'by_team' => $byTeam,
        ];
    }

    /**
     * @return array{by_team:array<int, array<string, float>>, league_avg_predictive_rating:float}
     */
    protected function teamMetricContext(int $season, ?string $cutoffDate = null): array
    {
        $rows = TeamMetric::query()
            ->where('season', $season)
            ->when($cutoffDate !== null, fn ($query) => $query->whereDate('calculation_date', '<=', $cutoffDate))
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->get([
                'team_id',
                'predictive_rating',
                'future_strength_of_schedule',
            ])
            ->unique('team_id')
            ->values();

        $byTeam = [];

        foreach ($rows as $row) {
            $byTeam[(int) $row->team_id] = [
                'predictive_rating' => (float) ($row->predictive_rating ?? 0.0),
                'future_strength_of_schedule' => (float) ($row->future_strength_of_schedule ?? 0.0),
            ];
        }

        $leagueAverage = $rows->count() > 0
            ? (float) $rows->avg(fn ($row) => (float) ($row->predictive_rating ?? 0.0))
            : 0.0;

        return [
            'by_team' => $byTeam,
            'league_avg_predictive_rating' => $leagueAverage,
        ];
    }

    /**
     * @return array<int, array<int, int>>
     */
    protected function remainingOpponentsByTeam(int $season, ?int $asOfWeek = null): array
    {
        $games = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', $this->regularSeasonTypeCandidates())
            ->when(
                $asOfWeek !== null,
                fn ($query) => $query->where('week', '>', $asOfWeek),
                fn ($query) => $query->where('status', '!=', config('nfl.statuses.final'))
            )
            ->get(['home_team_id', 'away_team_id']);

        $byTeam = [];

        foreach ($games as $game) {
            $homeTeamId = (int) ($game->home_team_id ?? 0);
            $awayTeamId = (int) ($game->away_team_id ?? 0);

            if ($homeTeamId > 0 && $awayTeamId > 0) {
                $byTeam[$homeTeamId][] = $awayTeamId;
                $byTeam[$awayTeamId][] = $homeTeamId;
            }
        }

        return $byTeam;
    }

    protected function availabilityFactor(?string $status): float
    {
        if ($status === null || trim($status) === '') {
            return 1.0;
        }

        $normalized = strtolower(trim($status));
        $map = (array) config('nfl.player_futures.injury_availability', []);

        foreach ($map as $needle => $factor) {
            if (str_contains($normalized, strtolower((string) $needle))) {
                return (float) $factor;
            }
        }

        return 1.0;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function positionSupportsMarket(string $position, array $definition): bool
    {
        $allowed = $definition['positions'] ?? [];

        return $allowed === [] || in_array($position, $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function projectedPerGame(
        float $currentTotal,
        int $gamesPlayed,
        string $position,
        string $archetype,
        array $definition
    ): float
    {
        $priorGames = (float) config('nfl.player_futures.prior_games', 4);
        $positionPriors = (array) ($definition['prior_per_game_by_position'] ?? []);
        $archetypePriors = (array) ($definition['prior_per_game_by_archetype'] ?? []);
        $priorPerGame = (float) ($archetypePriors[$archetype] ?? $positionPriors[$position] ?? 0.0);

        $denominator = max(1.0, $gamesPlayed + $priorGames);

        return ($currentTotal + ($priorGames * $priorPerGame)) / $denominator;
    }

    protected function currentUsageShare(int $teamId, string $statField, float $currentTotal, array $teamTotals): float
    {
        $teamTotal = (float) ($teamTotals[$teamId][$statField] ?? 0.0);
        if ($teamTotal <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $currentTotal / $teamTotal));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function stabilizedUsageShare(float $currentShare, int $gamesPlayed, string $archetype, array $definition): float
    {
        $priorGames = (float) config('nfl.player_futures.prior_share_games', 6);
        $priorShare = (float) (($definition['prior_share_by_archetype'][$archetype] ?? null) ?? $currentShare);
        $denominator = max(1.0, $gamesPlayed + $priorGames);

        return max(0.0, min(1.0, (($currentShare * $gamesPlayed) + ($priorShare * $priorGames)) / $denominator));
    }

    protected function teamOpportunityPerGame(
        int $teamId,
        string $statField,
        array $teamTotals,
        array $teamScheduleSummary
    ): float {
        $gamesCompleted = max(1, (int) ($teamScheduleSummary['games_completed'] ?? 0));

        return (float) ($teamTotals[$teamId][$statField] ?? 0.0) / $gamesCompleted;
    }

    protected function blendedPerGameProjection(float $directPerGame, float $usageSharePerGame): float
    {
        $directWeight = (float) config('nfl.player_futures.direct_rate_weight', 0.45);
        $usageWeight = (float) config('nfl.player_futures.usage_share_weight', 0.55);

        return ($directPerGame * $directWeight) + ($usageSharePerGame * $usageWeight);
    }

    /**
     * @param  array<string, mixed>|null  $depthEntry
     */
    protected function archetype(string $position, ?array $depthEntry): string
    {
        $depthRank = (int) ($depthEntry['depth_rank'] ?? 99);
        $isStarter = (bool) ($depthEntry['is_starter'] ?? false);

        return match ($position) {
            'QB' => $isStarter || $depthRank === 1 ? 'qb_starter' : 'qb_backup',
            'RB', 'HB', 'FB' => $isStarter || $depthRank === 1 ? 'rb_lead' : 'rb_rotation',
            'WR' => $depthRank === 1 ? 'wr_alpha' : 'wr_secondary',
            'TE' => $depthRank === 1 ? 'te_primary' : 'te_secondary',
            default => $isStarter ? 'generic_starter' : 'generic_backup',
        };
    }

    /**
     * @param  array<string, mixed>|null  $depthEntry
     */
    protected function roleMultiplier(string $archetype, ?array $depthEntry): float
    {
        $config = (array) config('nfl.player_futures.role_multipliers', []);
        $multiplier = (float) ($config[$archetype] ?? 1.0);

        if ($depthEntry === null) {
            return $multiplier;
        }

        $depthRank = (int) ($depthEntry['depth_rank'] ?? 99);
        if ($depthRank >= 3) {
            $multiplier *= 0.92;
        }

        return max(0.75, min(1.20, $multiplier));
    }

    /**
     * @param  array<int, array<string, mixed>>  $teamEntries
     * @param  array<int, string>  $injuryStatuses
     */
    protected function competitionInjuryBoost(
        int $playerId,
        string $market,
        array $teamEntries,
        array $injuryStatuses,
    ): float {
        $group = $this->usageCompetitionGroup($market);
        $boost = 1.0;
        $perWeight = (float) config('nfl.player_futures.teammate_injury_boost_per_weight', 0.08);
        $maxBoost = (float) config('nfl.player_futures.max_teammate_injury_boost', 0.18);

        foreach ($teamEntries as $entry) {
            $teammateId = (int) ($entry['player_id'] ?? 0);
            if ($teammateId <= 0 || $teammateId === $playerId) {
                continue;
            }

            if (! $this->entryMatchesUsageGroup($entry, $group)) {
                continue;
            }

            $status = $injuryStatuses[$teammateId] ?? null;
            if ($status === null) {
                continue;
            }

            $severity = 1.0 - $this->availabilityFactor($status);
            $depthWeight = $this->depthWeight($entry);
            $boost += ($severity * $depthWeight * $perWeight);
        }

        return min(1.0 + $maxBoost, $boost);
    }

    /**
     * @param  array<int, array<int, int>>  $remainingOpponents
     * @param  array{by_team:array<int, array<string, float>>, league_avg_predictive_rating:float}  $teamMetrics
     */
    protected function scheduleAdjustmentFactor(
        int $teamId,
        array $remainingOpponents,
        array $teamMetrics,
    ): float {
        $leagueAverage = (float) ($teamMetrics['league_avg_predictive_rating'] ?? 0.0);
        $opponentRatings = collect($remainingOpponents[$teamId] ?? [])
            ->map(fn (int $opponentId): ?float => isset($teamMetrics['by_team'][$opponentId])
                ? (float) $teamMetrics['by_team'][$opponentId]['predictive_rating']
                : null)
            ->filter(fn ($value) => $value !== null)
            ->values();

        if ($opponentRatings->isEmpty()) {
            $futureSchedule = (float) ($teamMetrics['by_team'][$teamId]['future_strength_of_schedule'] ?? $leagueAverage);
            $opponentAverage = $futureSchedule !== 0.0 ? $futureSchedule : $leagueAverage;
        } else {
            $opponentAverage = (float) $opponentRatings->avg();
        }

        $divisor = max(1.0, (float) config('nfl.player_futures.schedule_adjustment_divisor', 400.0));
        $factor = 1.0 - (($opponentAverage - $leagueAverage) / $divisor);

        return max(
            (float) config('nfl.player_futures.min_schedule_adjustment', 0.90),
            min((float) config('nfl.player_futures.max_schedule_adjustment', 1.10), $factor)
        );
    }

    /**
     * @param  array<int, float>  $values
     */
    protected function stddev(array $values, float $fallback): float
    {
        $count = count($values);

        if ($count < 2) {
            return max(1.0, $fallback);
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(
            static fn (float $value): float => ($value - $mean) ** 2,
            $values
        )) / max(1, $count - 1);

        return max(1.0, sqrt($variance));
    }

    protected function normalTailProbability(float $line, float $mean, float $stddev): float
    {
        $z = ($line - $mean) / max(1e-6, $stddev);

        return round(1 - $this->normalCdf($z), 4);
    }

    protected function normalCdf(float $x): float
    {
        return 0.5 * (1 + $this->errorFunction($x / sqrt(2)));
    }

    protected function errorFunction(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $t = 1.0 / (1.0 + ($p * $x));
        $y = 1.0 - (((((($a5 * $t) + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * @return array<int, int|string>
     */
    protected function regularSeasonTypeCandidates(): array
    {
        $regular = config('nfl.season.types.regular', 2);

        return array_values(array_unique([
            $regular,
            (string) $regular,
            'regular',
            'reg',
        ]));
    }

    protected function sortValue(array $row, string $sortBy): float
    {
        return match ($sortBy) {
            'projected_total' => (float) ($row['projected_total'] ?? 0),
            'current_total' => (float) ($row['current_total'] ?? 0),
            'over_probability' => (float) ($row['over_probability'] ?? 0),
            'under_probability' => (float) ($row['under_probability'] ?? 0),
            default => (float) ($row['best_edge_probability'] ?? -INF),
        };
    }

    protected function bestEdgeProbability(?float $edgeOver, ?float $edgeUnder): ?float
    {
        $values = array_values(array_filter(
            [$edgeOver, $edgeUnder],
            static fn ($value) => $value !== null
        ));

        if ($values === []) {
            return null;
        }

        return max($values);
    }

    protected function usageCompetitionGroup(string $market): string
    {
        return match ($market) {
            'passing_yards', 'passing_touchdowns' => 'qb',
            'rushing_yards', 'rushing_touchdowns' => 'rush',
            'receptions', 'receiving_yards', 'receiving_touchdowns' => 'target',
            default => 'generic',
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function entryMatchesUsageGroup(array $entry, string $group): bool
    {
        $positionCode = strtoupper((string) ($entry['position_code'] ?? ''));
        $positionSlotKey = strtoupper((string) ($entry['position_slot_key'] ?? ''));
        $haystack = $positionCode.' '.$positionSlotKey.' '.strtoupper((string) ($entry['position_name'] ?? ''));

        return match ($group) {
            'qb' => str_contains($haystack, 'QB'),
            'rush' => str_contains($haystack, 'RB') || str_contains($haystack, 'HB') || str_contains($haystack, 'FB') || str_contains($haystack, 'QB'),
            'target' => str_contains($haystack, 'WR') || str_contains($haystack, 'TE') || str_contains($haystack, 'RB'),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function depthWeight(array $entry): float
    {
        if ((bool) ($entry['is_starter'] ?? false)) {
            return 1.0;
        }

        $depthRank = (int) ($entry['depth_rank'] ?? 99);

        return match (true) {
            $depthRank <= 2 => 0.65,
            $depthRank <= 3 => 0.4,
            default => 0.2,
        };
    }

    protected function cutoffDate(int $season, ?int $asOfWeek): ?string
    {
        if ($asOfWeek === null) {
            return null;
        }

        $value = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', $this->regularSeasonTypeCandidates())
            ->where('week', '<=', $asOfWeek)
            ->max('game_date');

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }
}
