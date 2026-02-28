<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractPlayController extends AbstractSportsApiController
{
    protected const PLAY_MODEL = '';

    protected const GAME_MODEL = '';

    protected const PLAY_RESOURCE = '';

    protected function getPlayModel(): string
    {
        if (static::PLAY_MODEL === '') {
            throw new \RuntimeException('PLAY_MODEL must be defined on play controller.');
        }

        return static::PLAY_MODEL;
    }

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on play controller.');
        }

        return static::GAME_MODEL;
    }

    protected function getPlayResource(): string
    {
        if (static::PLAY_RESOURCE === '') {
            throw new \RuntimeException('PLAY_RESOURCE must be defined on play controller.');
        }

        return static::PLAY_RESOURCE;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->getPlayModel();
        $resource = $this->getPlayResource();

        $plays = $model::query()
            ->with(['game'])
            ->orderByDesc('id')
            ->paginate($this->getPerPage($request));

        return $resource::collection($plays);
    }

    public function show($play): JsonResource
    {
        $model = $this->getPlayModel();
        $resource = $this->getPlayResource();
        $playId = $this->requireNumericId($play);

        $play = $model::query()
            ->with(['game'])
            ->findOrFail($playId);

        return new $resource($play);
    }

    public function byGame($game, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $model = $this->getPlayModel();
        $resource = $this->getPlayResource();
        $gameId = $this->requireNumericId($game);

        $gameModel::query()->findOrFail($gameId);

        $plays = $model::query()
            ->where('game_id', $gameId)
            ->orderBy('id')
            ->paginate($this->getPerPage($request));

        return $resource::collection($plays);
    }
}
