<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractPlayerController extends AbstractSportsApiController
{
    protected const PLAYER_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const PLAYER_RESOURCE = '';

    protected const ORDER_BY_COLUMN = 'full_name';

    protected const BY_TEAM_PAGINATED = true;

    protected function getPlayerModel(): string
    {
        if (static::PLAYER_MODEL === '') {
            throw new \RuntimeException('PLAYER_MODEL must be defined on player controller.');
        }

        return static::PLAYER_MODEL;
    }

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on player controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getPlayerResource(): string
    {
        if (static::PLAYER_RESOURCE === '') {
            throw new \RuntimeException('PLAYER_RESOURCE must be defined on player controller.');
        }

        return static::PLAYER_RESOURCE;
    }

    protected function getOrderByColumn(): string
    {
        return static::ORDER_BY_COLUMN;
    }

    protected function byTeamPaginated(): bool
    {
        return static::BY_TEAM_PAGINATED;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->getPlayerModel();
        $resource = $this->getPlayerResource();

        $players = $model::query()
            ->with('team')
            ->orderBy($this->getOrderByColumn())
            ->paginate($this->getPerPage($request));

        return $resource::collection($players);
    }

    public function show($player): JsonResource
    {
        $model = $this->getPlayerModel();
        $resource = $this->getPlayerResource();
        $playerId = $this->requireNumericId($player);

        $player = $model::query()
            ->with('team')
            ->findOrFail($playerId);

        return new $resource($player);
    }

    public function byTeam($team, Request $request): AnonymousResourceCollection
    {
        $teamModel = $this->getTeamModel();
        $model = $this->getPlayerModel();
        $resource = $this->getPlayerResource();
        $teamId = $this->requireNumericId($team);

        $teamModel::query()->findOrFail($teamId);

        $query = $model::query()
            ->where('team_id', $teamId)
            ->orderBy($this->getOrderByColumn());

        if ($this->byTeamPaginated()) {
            return $resource::collection($query->paginate($this->getPerPage($request)));
        }

        return $resource::collection($query->get());
    }
}
