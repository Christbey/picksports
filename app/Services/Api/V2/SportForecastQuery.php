<?php

namespace App\Services\Api\V2;

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
use App\Models\WCBB\TournamentForecast as WcbbTournamentForecast;
use App\Services\NFL\TeamPlayoffForecastService;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Support\BasketballSeason;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SportForecastQuery
{
    public function __construct(
        private readonly FuturesOddsLookupService $futuresOddsLookup,
        private readonly FuturesEdgeService $futuresEdgeService,
        private readonly SportsViewCache $sportsViewCache,
        private readonly TeamPlayoffForecastService $nflForecastService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function get(SportContext $context, array $filters = []): array
    {
        $payload = match ($context->slug) {
            'nba' => $this->nba($context, $filters),
            'mlb' => $this->mlb($context, $filters),
            'nfl' => $this->nfl($context, $filters),
            'cbb' => $this->cbb($context, $filters),
            'wcbb' => $this->wcbb($context, $filters),
            default => abort(404, "Forecasts are not available for {$context->slug}."),
        };

        // Re-evaluate at read time: cached differences must not outlive their source quote.
        $payload['data'] = $this->withQuoteFreshness($payload['data']);

        return $payload;
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

        $payload = $this->remember($context, $filters, function () use ($season, $asOfDate, $requireHistoricalMetrics, $sortBy, $direction): array {
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

            return [
                'data' => $data,
                'meta' => [
                    'season' => $season,
                    'as_of_date' => $asOfDate,
                    'require_historical_metrics' => $requireHistoricalMetrics,
                    'sort_by' => $sortBy,
                    'sort_direction' => $direction,
                    'simulations' => data_get($report, 'summary.simulations'),
                    'attempted_simulations' => data_get($report, 'summary.attempted_simulations'),
                    'completed_postseason_games' => data_get($report, 'summary.completed_postseason_games'),
                    'scheduled_postseason_games' => data_get($report, 'summary.scheduled_postseason_games'),
                    'warnings' => $report['warnings'] ?? [],
                    'generated_at' => $report['generated_at'] ?? null,
                    'model_version' => $report['model_version'] ?? null,
                    'tie_model' => $report['tie_model'] ?? null,
                    'projected_bracket' => $report['projected_bracket'] ?? [],
                    'probability_status' => 'uncalibrated_model_estimate',
                    'seed_definition' => 'Average seed when making the playoffs',
                    'odds_stale_after_hours' => 36,
                ],
            ];
        });
        $data = $payload['data'];
        $data = $asOfDate === null
            ? $this->withMarketEdges($data, 'nfl', $season, 'super_bowl_champion_probability')
            : $this->futuresEdgeService->annotate($data, 'super_bowl_champion_probability');
        $data = array_map(function (array $row): array {
            $captured = data_get($row, 'market_odds.fetched_at');
            try {
                $date = $captured ? Carbon::parse($captured) : null;
            } catch (\Throwable) {
                $date = null;
            }
            $stale = ! $date || $date->isFuture() || $date->lt(now()->subHours(36));
            if (is_array($row['market_odds'] ?? null)) {
                $row['market_odds']['stale'] = $stale;
            }
            if ($stale) {
                $row['market_edge']['edge_probability'] = null;
                $row['market_edge']['edge_percent_points'] = null;
                $row['market_edge']['has_edge'] = false;
            }
            $row['market_edge']['actionable'] = false;

            return $row;
        }, $data);
        $data = NflPlayoffForecastResource::collection(collect($data))->resolve();

        $payload['data'] = $data;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function cbb(SportContext $context, array $filters): array
    {
        $season = (int) ($filters['season'] ?? (config('cbb.season.default') ?? BasketballSeason::forDate()));
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
            'contract' => $context->slug === 'nfl' ? 'sports.forecasts.index.bracket-status.quote-evidence-v2.'.TeamPlayoffForecastService::MODEL_VERSION : 'sports.forecasts.index.quote-evidence-v2',
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

    /** Stored prices remain visible; unavailable comparisons are never betting approvals. */
    private function withQuoteFreshness(array $rows): array
    {
        $now = now();

        return array_map(function (array $row) use ($now): array {
            $quote = $row['market_odds'] ?? null;
            $stale = ! is_array($quote);
            if (is_array($quote)) {
                foreach (['fetched_at', 'market_last_update'] as $key) {
                    $value = $quote[$key] ?? null;
                    if ($key === 'market_last_update' && $value === null) {
                        continue;
                    }
                    try {
                        $observed = $value ? Carbon::parse($value) : null;
                    } catch (\Throwable) {
                        $observed = null;
                    }
                    if ($observed === null || $observed->gt($now) || $observed->lt($now->copy()->subRealHours(36))) {
                        $stale = true;
                    }
                }
                $row['market_odds']['stale'] = $stale;
            }
            if ($stale) {
                $row['market_edge']['edge_probability'] = null;
                $row['market_edge']['edge_percent_points'] = null;
                $row['market_edge']['has_edge'] = false;
            }
            $row['market_edge']['actionable'] = false;

            return $row;
        }, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    private function withMarketEdges(array $data, string $sport, int $season, string $probabilityKey): array
    {
        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason(
            $sport,
            $season,
            $this->futuresOddsLookup->championshipMarketKeys()
        );

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
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
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
            'selection_score' => null,
            'projected_seed' => null,
            'auto_bid' => false,
            'auto_bid_probability' => null,
            'at_large_probability' => null,
            'tournament_make_probability' => null,
            'first_four_probability' => null,
            'first_four_auto_probability' => null,
            'first_four_at_large_probability' => null,
            'bid_thief_probability' => null,
            'champion_probability' => null,
            'final_four_probability' => null,
            'title_game_probability' => null,
            'games_final_count' => 0,
            'round_of_32_probability' => null,
            'sweet_16_probability' => null,
            'elite_8_probability' => null,
            'simulation_runs' => 0,
            'forecast_available' => false,
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
}
