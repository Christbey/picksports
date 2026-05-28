<?php

namespace App\Http\Controllers\Api\Sports;

use App\Services\Sports\GameMatchupContextService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractGameController extends AbstractSportsApiController
{
    protected const GAME_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const GAME_RESOURCE = '';

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on game controller.');
        }

        return static::GAME_MODEL;
    }

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on game controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getGameResource(): string
    {
        if (static::GAME_RESOURCE === '') {
            throw new \RuntimeException('GAME_RESOURCE must be defined on game controller.');
        }

        return static::GAME_RESOURCE;
    }

    /**
     * @return array<int, string>
     */
    protected function defaultGameRelations(bool $includePrediction = true): array
    {
        $relations = ['homeTeam', 'awayTeam'];

        if ($includePrediction) {
            $relations[] = 'prediction';
        }

        return $relations;
    }

    /**
     * @return array<int, string>
     */
    protected function additionalGameRelations(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function gameRelations(bool $includePrediction = true): array
    {
        return array_values(array_unique(array_merge(
            $this->defaultGameRelations($includePrediction),
            $this->additionalGameRelations()
        )));
    }

    /**
     * Display a listing of games
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $games = $gameModel::query()
            ->with($this->gameRelations(true))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->season, fn ($q, $season) => $q->where('season', $season));

        $this->applyIndexQueryFilters($games, $request);

        $games = $games
            ->orderByDesc('game_date')
            ->paginate($this->getPerPage($request));

        return $resourceClass::collection($games);
    }

    /**
     * Display the specified game
     */
    public function show($game): JsonResource
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();
        $gameId = $this->requireNumericId($game);

        $query = $gameModel::query()->with($this->gameRelations(true));

        $this->applyShowQueryFilters($query);

        $game = $query->findOrFail($gameId);
        $game->setAttribute('matchup_context', app(GameMatchupContextService::class)->forGame($game));

        return new $resourceClass($game);
    }

    /**
     * Display games for a specific team
     */
    public function byTeam($team, Request $request): AnonymousResourceCollection|JsonResponse
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();
        $teamId = $this->requireNumericId($team);
        $perPage = $this->getPerPage($request);
        $page = $request->integer('page') ?: 1;

        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'team_id' => $teamId,
            'query' => $request->query(),
            'per_page' => $perPage,
            'page' => $page,
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_games_by_team',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_games_by_team_seconds', 60),
            resolver: function () use ($gameModel, $resourceClass, $teamId, $perPage) {
                $games = $gameModel::query()
                    ->with(array_values(array_unique(array_merge(
                        $this->gameRelations(false),
                        ['teamStats']
                    ))))
                    ->where(function ($query) use ($teamId) {
                        $query->where('home_team_id', $teamId)
                            ->orWhere('away_team_id', $teamId);
                    });

                $this->applyIndexQueryFilters($games, request());

                $games = $games
                    ->orderByDesc('game_date')
                    ->paginate($perPage);

                return $resourceClass::collection($games)->response()->getData(true);
            },
        );

        return response()->json($payload);
    }

    /**
     * Display games for a specific season
     */
    public function bySeason($season, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();
        $seasonValue = $this->requireNumericId($season);

        $games = $gameModel::query()
            ->with($this->gameRelations(false))
            ->where('season', $seasonValue);

        $this->applyIndexQueryFilters($games, $request);

        $games = $games
            ->orderByDesc('game_date')
            ->paginate($this->getPerPage($request, 50));

        return $resourceClass::collection($games);
    }

    /**
     * Display games for a specific week
     */
    public function byWeek($season, $week, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();
        $seasonValue = $this->requireNumericId($season);
        $weekValue = $this->requireNumericId($week);
        $perPage = $this->getPerPage($request, 50);

        $games = $gameModel::query()
            ->with($this->gameRelations(true))
            ->where('season', $seasonValue)
            ->where('week', $weekValue);

        $this->applyIndexQueryFilters($games, $request);

        $games = $games
            ->oldest('game_date')
            ->paginate($perPage);

        return $resourceClass::collection($games);
    }

    protected function applyIndexQueryFilters($query, Request $request): void
    {
        //
    }

    protected function applyShowQueryFilters($query): void
    {
        //
    }
}
