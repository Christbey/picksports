<?php

namespace App\Http\Controllers\Api\Sports;

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
            ->when($request->season, fn ($q, $season) => $q->where('season', $season))
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 15);

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

        $game = $gameModel::query()->with($this->gameRelations(true))->findOrFail($gameId);

        return new $resourceClass($game);
    }

    /**
     * Display games for a specific team
     */
    public function byTeam($team, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();
        $teamId = $this->requireNumericId($team);

        $games = $gameModel::query()
            ->with(array_values(array_unique(array_merge(
                $this->gameRelations(false),
                ['teamStats']
            ))))
            ->where(function ($query) use ($teamId) {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 15);

        return $resourceClass::collection($games);
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
            ->where('season', $seasonValue)
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 50);

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

        $games = $gameModel::query()
            ->with($this->gameRelations(true))
            ->where('season', $seasonValue)
            ->where('week', $weekValue)
            ->oldest('game_date')
            ->get();

        return $resourceClass::collection($games);
    }
}
