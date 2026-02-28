<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractTeamStatController extends AbstractSportsApiController
{
    protected const TEAM_STAT_MODEL = '';

    protected const GAME_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const TEAM_STAT_RESOURCE = '';

    protected const INDEX_ORDER_BY_COLUMN = 'id';

    protected const BY_TEAM_ORDER_BY_COLUMN = 'id';

    protected function getTeamStatModel(): string
    {
        if (static::TEAM_STAT_MODEL === '') {
            throw new \RuntimeException('TEAM_STAT_MODEL must be defined on team stat controller.');
        }

        return static::TEAM_STAT_MODEL;
    }

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on team stat controller.');
        }

        return static::GAME_MODEL;
    }

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on team stat controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getTeamStatResource(): string
    {
        if (static::TEAM_STAT_RESOURCE === '') {
            throw new \RuntimeException('TEAM_STAT_RESOURCE must be defined on team stat controller.');
        }

        return static::TEAM_STAT_RESOURCE;
    }

    protected function getIndexOrderByColumn(): string
    {
        return static::INDEX_ORDER_BY_COLUMN;
    }

    protected function getByTeamOrderByColumn(): string
    {
        return static::BY_TEAM_ORDER_BY_COLUMN;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->getTeamStatModel();
        $resource = $this->getTeamStatResource();

        $stats = $model::query()
            ->with(['team', 'game'])
            ->orderByDesc($this->getIndexOrderByColumn())
            ->paginate($this->getPerPage($request));

        return $resource::collection($stats);
    }

    public function show($teamStat): JsonResource
    {
        $model = $this->getTeamStatModel();
        $resource = $this->getTeamStatResource();
        $teamStatId = $this->requireNumericId($teamStat);

        $teamStat = $model::query()
            ->with(['team', 'game'])
            ->findOrFail($teamStatId);

        return new $resource($teamStat);
    }

    public function byGame($game, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $model = $this->getTeamStatModel();
        $resource = $this->getTeamStatResource();
        $gameId = $this->requireNumericId($game);

        $gameModel::query()->findOrFail($gameId);

        $stats = $model::query()
            ->with(['team'])
            ->where('game_id', $gameId)
            ->paginate($this->getPerPage($request));

        return $resource::collection($stats);
    }

    public function byTeam($team, Request $request): AnonymousResourceCollection
    {
        $teamModel = $this->getTeamModel();
        $model = $this->getTeamStatModel();
        $resource = $this->getTeamStatResource();
        $teamId = $this->requireNumericId($team);

        $teamModel::query()->findOrFail($teamId);

        $stats = $model::query()
            ->with(['game'])
            ->where('team_id', $teamId)
            ->orderByDesc($this->getByTeamOrderByColumn())
            ->paginate($this->getPerPage($request));

        return $resource::collection($stats);
    }
}
