<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Resources\PlayerLeaderboardResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

abstract class AbstractPlayerStatController extends AbstractSportsApiController
{
    protected const PLAYER_STAT_MODEL = '';

    protected const PLAYER_MODEL = '';

    protected const GAME_MODEL = '';

    protected const PLAYER_STAT_RESOURCE = '';

    protected function getPlayerStatModel(): string
    {
        if (static::PLAYER_STAT_MODEL === '') {
            throw new \RuntimeException('PLAYER_STAT_MODEL must be defined on player stat controller.');
        }

        return static::PLAYER_STAT_MODEL;
    }

    protected function getPlayerModel(): string
    {
        if (static::PLAYER_MODEL === '') {
            throw new \RuntimeException('PLAYER_MODEL must be defined on player stat controller.');
        }

        return static::PLAYER_MODEL;
    }

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on player stat controller.');
        }

        return static::GAME_MODEL;
    }

    protected function getPlayerStatResource(): string
    {
        if (static::PLAYER_STAT_RESOURCE === '') {
            throw new \RuntimeException('PLAYER_STAT_RESOURCE must be defined on player stat controller.');
        }

        return static::PLAYER_STAT_RESOURCE;
    }

    protected function getByPlayerPerPage(Request $request): int
    {
        return $this->getPerPage($request);
    }

    /**
     * @return string[]
     */
    protected function getByGameRelations(): array
    {
        return ['player'];
    }

    /**
     * @return string[]
     */
    protected function getByPlayerRelations(): array
    {
        return ['game'];
    }

    protected function supportsLeaderboard(): bool
    {
        return false;
    }

    protected function getLeaderboardData(Request $request): Collection
    {
        return collect();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->getPlayerStatModel();
        $resource = $this->getPlayerStatResource();

        $stats = $model::query()
            ->with(['player', 'game'])
            ->orderByDesc('id')
            ->paginate($this->getPerPage($request));

        return $resource::collection($stats);
    }

    public function show($playerStat): JsonResource
    {
        $model = $this->getPlayerStatModel();
        $resource = $this->getPlayerStatResource();
        $playerStatId = $this->requireNumericId($playerStat);

        $playerStat = $model::query()
            ->with(['player', 'game'])
            ->findOrFail($playerStatId);

        return new $resource($playerStat);
    }

    public function byGame($game, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $model = $this->getPlayerStatModel();
        $resource = $this->getPlayerStatResource();
        $gameId = $this->requireNumericId($game);

        $gameModel::query()->findOrFail($gameId);

        $stats = $model::query()
            ->with($this->getByGameRelations())
            ->where('game_id', $gameId)
            ->paginate($this->getPerPage($request));

        return $resource::collection($stats);
    }

    public function byPlayer($player, Request $request): AnonymousResourceCollection
    {
        $playerModel = $this->getPlayerModel();
        $model = $this->getPlayerStatModel();
        $resource = $this->getPlayerStatResource();
        $playerId = $this->requireNumericId($player);

        $playerModel::query()->findOrFail($playerId);

        $stats = $model::query()
            ->with($this->getByPlayerRelations())
            ->where('player_id', $playerId)
            ->orderByDesc('id')
            ->paginate($this->getByPlayerPerPage($request));

        return $resource::collection($stats);
    }

    public function leaderboard(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if (! $this->supportsLeaderboard()) {
            return response()->json(['message' => 'Leaderboard not available for this sport'], 404);
        }

        return PlayerLeaderboardResource::collection($this->getLeaderboardData($request));
    }

}
