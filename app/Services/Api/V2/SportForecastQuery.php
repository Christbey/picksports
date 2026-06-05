<?php

namespace App\Services\Api\V2;

use App\Actions\WCBB\GenerateTournamentForecast;
use App\Http\Resources\CBB\TournamentForecastResource as CbbTournamentForecastResource;
use App\Http\Resources\MLB\PlayoffForecastResource as MlbPlayoffForecastResource;
use App\Http\Resources\NBA\PlayoffForecastResource as NbaPlayoffForecastResource;
use App\Http\Resources\NFL\PlayoffForecastResource as NflPlayoffForecastResource;
use App\Http\Resources\WCBB\TournamentForecastResource as WcbbTournamentForecastResource;
use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CBB\TournamentForecast as CbbTournamentForecast;
use App\Models\CBB\TournamentStateSnapshot;
use App\Models\MLB\PlayoffForecast as MlbPlayoffForecast;
use App\Models\MLB\TeamMetric as MlbTeamMetric;
use App\Models\NBA\PlayoffForecast as NbaPlayoffForecast;
use App\Models\WCBB\TeamMetric as WcbbTeamMetric;
use App\Models\WCBB\TournamentForecast as WcbbTournamentForecast;
use App\Services\NFL\TeamPlayoffForecastService;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SportForecastQuery
{
    public function __construct(
        private readonly FuturesOddsLookupService $futuresOddsLookup,
        private readonly FuturesEdgeService $futuresEdgeService,
        private readonly SportsViewCache $sportsViewCache,
        private readonly TeamPlayoffForecastService $nflForecastService,
        private readonly GenerateTournamentForecast $generateWcbbTournamentForecast,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function get(SportContext $context, array $filters = []): array
    {
        return match ($context->slug) {
            'nba' => $this->nba($context, $filters),
            'mlb' => $this->mlb($context, $filters),
            'nfl' => $this->nfl($context, $filters),
            'cbb' => $this->cbb($context, $filters),
            'wcbb' => $this->wcbb($context, $filters),
            default => abort(404, "Forecasts are not available for {$context->slug}."),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function nba(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? config('nba.season.default'));
        $sortBy = $this->sortBy($filters, [
            'champion_probability',
            'playoff_make_probability',
            'direct_playoff_probability',
            'play_in_tournament_probability',
            'division_win_probability',
            'nba_finals_probability',
            'conference_finals_probability',
            'selection_score',
            'conference_rank',
        ], 'champion_probability');
        $direction = $this->direction($filters);

        return $this->remember($context, $filters, function () use ($season, $sortBy, $direction): array {
            $requestedSeason = $season;
            $actualSeason = $season;
            $fallbackApplied = false;
            $forecasts = $this->playoffRows(NbaPlayoffForecast::class, $actualSeason, $sortBy, $direction, 'playoff_make_probability');

            if ($forecasts->isEmpty()) {
                $latestSeasonWithData = (int) (NbaPlayoffForecast::query()->max('season') ?? 0);
                if ($latestSeasonWithData > 0 && $latestSeasonWithData !== $actualSeason) {
                    $actualSeason = $latestSeasonWithData;
                    $fallbackApplied = true;
                    $forecasts = $this->playoffRows(NbaPlayoffForecast::class, $actualSeason, $sortBy, $direction, 'playoff_make_probability');
                }
            }

            $data = NbaPlayoffForecastResource::collection($forecasts)->resolve(request());
            $data = $this->withMarketEdges($data, 'nba', $actualSeason, 'champion_probability');

            return [
                'data' => $data,
                'meta' => [
                    'season' => $actualSeason,
                    'requested_season' => $requestedSeason,
                    'fallback_applied' => $fallbackApplied,
                    'available_seasons' => $this->availableSeasons(NbaPlayoffForecast::class),
                    'playoff_teams_per_conference' => (int) config('nba.playoff_forecast.playoff_teams_per_conference', 8),
                    'play_in_teams_per_conference' => (int) config('nba.playoff_forecast.play_in_teams_per_conference', 10),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function mlb(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? config('mlb.season.default'));
        $sortBy = $this->sortBy($filters, [
            'champion_probability',
            'playoff_make_probability',
            'world_series_probability',
            'league_championship_probability',
            'selection_score',
            'league_rank',
        ], 'champion_probability');
        $direction = $this->direction($filters);

        return $this->remember($context, $filters, function () use ($season, $sortBy, $direction): array {
            $forecasts = $this->playoffRows(MlbPlayoffForecast::class, $season, $sortBy, $direction, 'playoff_make_probability');
            $hasCurrentSeasonMetrics = MlbTeamMetric::query()
                ->where('season', $season)
                ->where('season_type', (string) config('mlb.season.types.regular', 2))
                ->exists();
            $projectionSourceSeason = $hasCurrentSeasonMetrics
                ? $season
                : MlbTeamMetric::query()
                    ->where('season', '<', $season)
                    ->where('season_type', (string) config('mlb.season.types.regular', 2))
                    ->max('season');

            $data = MlbPlayoffForecastResource::collection($forecasts)->resolve(request());
            $data = $this->withMarketEdges($data, 'mlb', $season, 'champion_probability');

            return [
                'data' => $data,
                'meta' => [
                    'season' => $season,
                    'available_seasons' => $this->availableSeasons(MlbPlayoffForecast::class),
                    'used_regression' => ! $hasCurrentSeasonMetrics && $projectionSourceSeason !== null,
                    'projection_source_season' => $projectionSourceSeason ? (int) $projectionSourceSeason : null,
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function nfl(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? config('nfl.season.default'));
        $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
        $requireHistoricalMetrics = (bool) ($filters['require_historical_metrics'] ?? false);
        $sortBy = $this->sortBy($filters, [
            'projected_wins',
            'make_playoffs_probability',
            'division_winner_probability',
            'conference_champion_probability',
            'super_bowl_champion_probability',
            'projected_seed',
        ], 'super_bowl_champion_probability');
        $direction = $this->direction($filters);

        return $this->remember($context, $filters, function () use ($season, $asOfDate, $requireHistoricalMetrics, $sortBy, $direction): array {
            $report = $this->nflForecastService->forecast(
                season: $season,
                asOfDate: $asOfDate,
                requireHistoricalMetrics: $requireHistoricalMetrics,
            );

            $data = array_values($report['teams'] ?? []);
            usort($data, function (array $left, array $right) use ($sortBy, $direction): int {
                $comparison = (($left[$sortBy] ?? 0) <=> ($right[$sortBy] ?? 0));

                return $direction === 'asc' ? $comparison : -$comparison;
            });

            $data = $this->withMarketEdges($data, 'nfl', $season, 'super_bowl_champion_probability');
            $data = NflPlayoffForecastResource::collection(collect($data))->resolve();

            return [
                'data' => $data,
                'meta' => [
                    'season' => $season,
                    'as_of_date' => $asOfDate,
                    'require_historical_metrics' => $requireHistoricalMetrics,
                    'sort_by' => $sortBy,
                    'sort_direction' => $direction,
                    'simulations' => data_get($report, 'summary.simulations'),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function cbb(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? config('cbb.season.default'));
        $sortBy = $this->sortBy($filters, [
            'champion_probability',
            'tournament_make_probability',
            'auto_bid_probability',
            'at_large_probability',
            'bid_thief_probability',
            'selection_score',
        ], 'champion_probability');
        $direction = $this->direction($filters);

        return $this->remember($context, $filters, function () use ($season, $sortBy, $direction): array {
            $latestSnapshot = TournamentStateSnapshot::query()
                ->where('season', $season)
                ->where('status', 'completed')
                ->latest('as_of')
                ->latest('id')
                ->first();
            $actualFieldByTeam = $latestSnapshot
                ? $this->actualFieldByTeamFromForecasts($latestSnapshot->forecasts()->with('team')->get())
                : $this->cbbActualFieldByTeam($season);
            $eliminatedTeamIds = $latestSnapshot ? [] : $this->cbbEliminatedTeamIds($season);
            $forecasts = CbbTournamentForecast::query()
                ->with('team')
                ->where('season', $season)
                ->when(
                    $latestSnapshot,
                    fn ($query) => $query->where('snapshot_id', $latestSnapshot->id),
                    fn ($query) => $query->where('mode', 'baseline')
                )
                ->orderBy($sortBy, $direction)
                ->orderBy('tournament_make_probability', 'desc')
                ->get();
            $missingActualTeams = collect();

            if (! $latestSnapshot) {
                $actualTeamIds = array_keys($actualFieldByTeam);
                $missingActualTeams = CbbTeam::query()
                    ->whereIn('id', array_values(array_diff($actualTeamIds, array_filter($forecasts->pluck('team_id')->all()))))
                    ->get();
            }

            $data = CbbTournamentForecastResource::collection($forecasts)->resolve(request());
            foreach ($missingActualTeams as $team) {
                $data[] = $this->cbbFallbackForecastRow($team, $season);
            }

            $data = $this->withMarketEdges($data, 'cbb', $season, 'champion_probability');
            $data = array_map(function (array $row) use ($actualFieldByTeam, $eliminatedTeamIds, $latestSnapshot): array {
                $teamId = $row['team_id'] !== null ? (int) $row['team_id'] : 0;

                if ($latestSnapshot) {
                    $row['is_actual_field'] = true;
                    $row['actual_round'] = $row['reached_round'];
                    $row['actual_region'] = $row['region'];
                    $row['actual_seed'] = $row['seed'];
                    $row['is_first_four'] = (bool) $row['is_first_four'];
                } else {
                    $field = $actualFieldByTeam[$teamId] ?? null;
                    $row['is_actual_field'] = $field !== null;
                    $row['is_first_four'] = (bool) ($field['is_first_four'] ?? false);
                    $row['actual_round'] = $field['round'] ?? null;
                    $row['actual_region'] = $field['region'] ?? null;
                    $row['actual_seed'] = $field['seed'] ?? null;
                }

                if (! $latestSnapshot) {
                    $row['is_eliminated'] = $teamId > 0 && isset($eliminatedTeamIds[$teamId]);
                }

                if ($row['is_eliminated']) {
                    $row['champion_probability'] = 0.0;
                    $row['final_four_probability'] = 0.0;
                    $row['title_game_probability'] = 0.0;
                }

                return $row;
            }, $data);

            return [
                'data' => $data,
                'meta' => [
                    'season' => $season,
                    'available_seasons' => $this->availableSeasons(CbbTournamentForecast::class),
                    'actual_field_size' => $latestSnapshot?->field_size ?? count($actualFieldByTeam),
                    'mode' => $latestSnapshot ? 'live_snapshot' : 'baseline',
                    'snapshot_id' => $latestSnapshot?->id,
                    'snapshot_as_of' => $latestSnapshot?->as_of?->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function wcbb(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? config('wcbb.season.default'));
        $sortBy = $this->sortBy($filters, [
            'champion_probability',
            'tournament_make_probability',
            'auto_bid_probability',
            'at_large_probability',
            'bid_thief_probability',
            'selection_score',
        ], 'champion_probability');
        $direction = $this->direction($filters);

        return $this->remember($context, $filters, function () use ($season, $sortBy, $direction): array {
            $this->refreshWcbbForecastIfNeeded($season);

            $forecasts = WcbbTournamentForecast::query()
                ->with('team')
                ->where('season', $season)
                ->orderBy($sortBy, $direction)
                ->orderBy('tournament_make_probability', 'desc')
                ->get();

            $data = WcbbTournamentForecastResource::collection($forecasts)->resolve(request());
            $data = $this->withMarketEdges($data, 'wcbb', $season, 'champion_probability');

            return [
                'data' => $data,
                'meta' => [
                    'season' => $season,
                    'available_seasons' => $this->availableSeasons(WcbbTournamentForecast::class),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $allowed
     */
    private function sortBy(array $filters, array $allowed, string $default): string
    {
        $sortBy = (string) ($filters['sort_by'] ?? $default);

        return in_array($sortBy, $allowed, true) ? $sortBy : $default;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function direction(array $filters): string
    {
        return strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  callable(): array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}  $resolver
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function remember(SportContext $context, array $filters, callable $resolver): array
    {
        $cacheKey = $this->sportsViewCache->contextHash([
            'contract' => 'sports.forecasts.index',
            'sport' => $context->slug,
            'filters' => $filters,
        ]);

        return $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_FUTURES_FORECASTS,
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.futures_forecasts_seconds', 120),
            resolver: $resolver,
        );
    }

    /**
     * @param  class-string  $modelClass
     * @return EloquentCollection<int, mixed>
     */
    private function playoffRows(string $modelClass, int $season, string $sortBy, string $direction, string $secondarySort): EloquentCollection
    {
        return $modelClass::query()
            ->with('team')
            ->where('season', $season)
            ->orderBy($sortBy, $direction)
            ->orderBy($secondarySort, 'desc')
            ->get();
    }

    /**
     * @param  class-string  $modelClass
     * @return Collection<int, int>
     */
    private function availableSeasons(string $modelClass): Collection
    {
        return $modelClass::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    private function withMarketEdges(array $data, string $sport, int $season, string $probabilityKey): array
    {
        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason($sport, $season);
        $data = array_map(function (array $row) use ($marketOddsByTeam): array {
            $teamId = (int) ($row['team_id'] ?? 0);
            $row['market_odds'] = $teamId > 0 ? ($marketOddsByTeam[$teamId] ?? null) : null;

            return $row;
        }, $data);

        return $this->futuresEdgeService->annotate($data, $probabilityKey);
    }

    /**
     * @return array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>
     */
    private function cbbActualFieldByTeam(int $season): array
    {
        return CbbGame::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->whereIn('tournament_round', ['first_four', 'round_of_64'])
            ->get()
            ->reduce(function (array $field, CbbGame $game): array {
                foreach ([
                    ['team_id' => $game->home_team_id, 'seed' => $game->home_seed],
                    ['team_id' => $game->away_team_id, 'seed' => $game->away_seed],
                ] as $participant) {
                    $teamId = (int) ($participant['team_id'] ?? 0);
                    if ($teamId <= 0) {
                        continue;
                    }

                    $current = $field[$teamId] ?? null;
                    $replacement = [
                        'seed' => $participant['seed'] !== null ? (int) $participant['seed'] : null,
                        'region' => $game->tournament_region,
                        'round' => $game->tournament_round,
                        'is_first_four' => $game->tournament_round === 'first_four',
                    ];

                    if ($current === null || ($current['is_first_four'] && ! $replacement['is_first_four'])) {
                        $field[$teamId] = $replacement;
                    }
                }

                return $field;
            }, []);
    }

    /**
     * @param  EloquentCollection<int, CbbTournamentForecast>  $forecasts
     * @return array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>
     */
    private function actualFieldByTeamFromForecasts(EloquentCollection $forecasts): array
    {
        return $forecasts
            ->filter(fn (CbbTournamentForecast $forecast) => $forecast->team_id !== null)
            ->mapWithKeys(fn (CbbTournamentForecast $forecast) => [
                $forecast->team_id => [
                    'seed' => $forecast->seed,
                    'region' => $forecast->region,
                    'round' => $forecast->reached_round,
                    'is_first_four' => (bool) $forecast->is_first_four,
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, true>
     */
    private function cbbEliminatedTeamIds(int $season): array
    {
        return CbbGame::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->where('status', config('cbb.statuses.final'))
            ->get()
            ->reduce(function (array $eliminated, CbbGame $game): array {
                $homeTeamId = (int) ($game->home_team_id ?? 0);
                $awayTeamId = (int) ($game->away_team_id ?? 0);

                if ($homeTeamId <= 0 || $awayTeamId <= 0) {
                    return $eliminated;
                }

                if (($game->home_score ?? null) > ($game->away_score ?? null)) {
                    $eliminated[$awayTeamId] = true;
                } elseif (($game->away_score ?? null) > ($game->home_score ?? null)) {
                    $eliminated[$homeTeamId] = true;
                }

                return $eliminated;
            }, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function cbbFallbackForecastRow(CbbTeam $team, int $season): array
    {
        return [
            'id' => null,
            'team_id' => $team->id,
            'season' => $season,
            'snapshot_id' => null,
            'as_of' => null,
            'mode' => 'baseline',
            'region' => null,
            'seed' => null,
            'is_first_four' => false,
            'is_alive' => true,
            'is_eliminated' => false,
            'reached_round' => null,
            'eliminated_round' => null,
            'selection_score' => 0.0,
            'projected_seed' => null,
            'auto_bid' => false,
            'auto_bid_probability' => 0.0,
            'at_large_probability' => 0.0,
            'tournament_make_probability' => 0.0,
            'first_four_probability' => 0.0,
            'first_four_auto_probability' => 0.0,
            'first_four_at_large_probability' => 0.0,
            'bid_thief_probability' => 0.0,
            'champion_probability' => 0.0,
            'final_four_probability' => 0.0,
            'title_game_probability' => 0.0,
            'games_final_count' => 0,
            'round_of_32_probability' => 0.0,
            'sweet_16_probability' => 0.0,
            'elite_8_probability' => 0.0,
            'simulation_runs' => 0,
            'team' => [
                'id' => $team->id,
                'espn_id' => $team->espn_id,
                'abbreviation' => $team->abbreviation,
                'location' => $team->school,
                'school' => $team->school,
                'mascot' => $team->mascot,
                'name' => $team->mascot,
                'display_name' => $team->school,
                'short_display_name' => $team->abbreviation,
                'conference' => $team->conference,
                'division' => $team->division,
                'color' => $team->color,
                'logo' => $team->logo_url,
                'logo_url' => $team->logo_url,
            ],
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function refreshWcbbForecastIfNeeded(int $season): void
    {
        $refreshConfig = (array) config('wcbb.tournament_forecast.refresh', []);
        if (($refreshConfig['enabled'] ?? true) !== true) {
            return;
        }

        $eligibleTeamCount = WcbbTeamMetric::query()
            ->where('season', $season)
            ->where('meets_minimum', true)
            ->count();

        if ($eligibleTeamCount === 0) {
            return;
        }

        $forecastSummary = WcbbTournamentForecast::query()
            ->where('season', $season)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as newest_updated_at')
            ->first();
        $newestUpdatedAt = $forecastSummary?->newest_updated_at;
        $rowCount = (int) ($forecastSummary?->row_count ?? 0);
        $coverageRatio = $eligibleTeamCount > 0 ? $rowCount / $eligibleTeamCount : 1.0;
        $minimumCoverageRatio = max(0.0, min(1.0, (float) ($refreshConfig['minimum_coverage_ratio'] ?? 0.95)));
        $staleAfterHours = max(1, (int) ($refreshConfig['stale_after_hours'] ?? 6));
        $isStale = ! $newestUpdatedAt instanceof CarbonInterface
            || $newestUpdatedAt->lt(now()->subHours($staleAfterHours));

        if ($rowCount === 0 || $coverageRatio < $minimumCoverageRatio || $isStale) {
            $this->generateWcbbTournamentForecast->execute($season);
        }
    }
}
