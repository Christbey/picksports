<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\TournamentForecastResource;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TournamentForecast;
use App\Support\SportsViewCache;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentForecastController extends Controller
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $season = (int) ($request->integer('season') ?: config('cbb.season.default'));
        $allowedSorts = [
            'champion_probability',
            'tournament_make_probability',
            'auto_bid_probability',
            'at_large_probability',
            'bid_thief_probability',
            'selection_score',
        ];

        $sortBy = (string) ($request->query('sort_by', 'champion_probability'));
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $cacheKey = $this->sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $season,
            'sort_by' => $sortBy,
            'sort_direction' => $direction,
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_FUTURES_FORECASTS,
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.futures_forecasts_seconds', 120),
            resolver: function () use ($season, $sortBy, $direction, $request): array {
                $actualFieldByTeam = $this->actualFieldByTeam($season);
                $eliminatedTeamIds = $this->eliminatedTeamIds($season);
                $forecasts = TournamentForecast::query()
                    ->with('team')
                    ->where('season', $season)
                    ->orderBy($sortBy, $direction)
                    ->orderBy('tournament_make_probability', 'desc')
                    ->get();
                $actualTeamIds = array_keys($actualFieldByTeam);
                $missingActualTeams = Team::query()
                    ->whereIn('id', array_values(array_diff($actualTeamIds, $forecasts->pluck('team_id')->all())))
                    ->get();

                $seasons = TournamentForecast::query()
                    ->select('season')
                    ->distinct()
                    ->orderByDesc('season')
                    ->pluck('season')
                    ->values();

                $data = TournamentForecastResource::collection($forecasts)->resolve($request);
                foreach ($missingActualTeams as $team) {
                    $data[] = $this->fallbackForecastRow($team, $season);
                }
                $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('cbb', $season);
                $data = array_map(function (array $row) use ($marketOddsByTeam): array {
                    $teamId = (int) ($row['team_id'] ?? 0);
                    $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

                    return $row;
                }, $data);
                $data = array_map(function (array $row) use ($actualFieldByTeam, $eliminatedTeamIds): array {
                    $teamId = (int) ($row['team_id'] ?? 0);
                    $field = $actualFieldByTeam[$teamId] ?? null;
                    $row['is_actual_field'] = $field !== null;
                    $row['is_first_four'] = (bool) ($field['is_first_four'] ?? false);
                    $row['actual_round'] = $field['round'] ?? null;
                    $row['actual_region'] = $field['region'] ?? null;
                    $row['actual_seed'] = $field['seed'] ?? null;
                    $row['is_eliminated'] = isset($eliminatedTeamIds[$teamId]);

                    if ($row['is_eliminated']) {
                        $row['champion_probability'] = 0.0;
                        $row['final_four_probability'] = 0.0;
                        $row['title_game_probability'] = 0.0;
                    }

                    return $row;
                }, $data);
                $data = $this->futuresEdgeService->annotate($data, 'champion_probability');

                return [
                    'data' => $data,
                    'meta' => [
                        'season' => $season,
                        'available_seasons' => $seasons,
                        'actual_field_size' => count($actualFieldByTeam),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }

    private function fallbackForecastRow(Team $team, int $season): array
    {
        return [
            'id' => null,
            'team_id' => $team->id,
            'season' => $season,
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

    /**
     * @return array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>
     */
    private function actualFieldByTeam(int $season): array
    {
        return Game::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->whereIn('tournament_round', ['first_four', 'round_of_64'])
            ->get()
            ->reduce(function (array $field, \App\Models\CBB\Game $game): array {
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
     * @return array<int, true>
     */
    private function eliminatedTeamIds(int $season): array
    {
        return Game::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->where('status', config('cbb.statuses.final'))
            ->get()
            ->reduce(function (array $eliminated, Game $game): array {
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
}
