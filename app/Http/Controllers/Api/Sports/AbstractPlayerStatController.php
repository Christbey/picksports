<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Resources\PlayerLeaderboardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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

    protected function applyByPlayerOrdering($query, Request $request)
    {
        $playerStatModel = $this->getPlayerStatModel();
        $playerStatInstance = new $playerStatModel;

        return $query->orderByDesc($playerStatInstance->getTable().'.id');
    }

    protected function applySeasonFiltersToStatsQuery($query, Request $request)
    {
        if (! $request->filled('season') && ! $request->filled('season_type')) {
            return $query;
        }

        $gameModel = $this->getGameModel();
        $gameInstance = new $gameModel;
        $gameTable = $gameInstance->getTable();
        $playerStatModel = $this->getPlayerStatModel();
        $playerStatInstance = new $playerStatModel;
        $playerStatTable = $playerStatInstance->getTable();

        $query->join($gameTable, "{$gameTable}.id", '=', "{$playerStatTable}.game_id");

        if ($request->filled('season') && $this->hasGameSeasonColumn()) {
            $query->where("{$gameTable}.{$this->getGameSeasonColumn()}", (int) $request->integer('season'));
        }

        $seasonTypeCandidates = $this->requestedSeasonTypeCandidates($request);
        if ($seasonTypeCandidates !== [] && $this->hasGameSeasonTypeColumn()) {
            $query->whereIn("{$gameTable}.{$this->getGameSeasonTypeColumn()}", $seasonTypeCandidates);
        }

        return $query->select("{$playerStatTable}.*");
    }

    protected function supportsLeaderboard(): bool
    {
        return false;
    }

    protected function getGameSeasonColumn(): string
    {
        return 'season';
    }

    protected function getGameSeasonTypeColumn(): string
    {
        return 'season_type';
    }

    protected function hasGameSeasonColumn(): bool
    {
        $gameModel = $this->getGameModel();
        $instance = new $gameModel;

        return Schema::hasColumn($instance->getTable(), $this->getGameSeasonColumn());
    }

    protected function hasGameSeasonTypeColumn(): bool
    {
        $gameModel = $this->getGameModel();
        $instance = new $gameModel;

        return Schema::hasColumn($instance->getTable(), $this->getGameSeasonTypeColumn());
    }

    protected function gamesTable(): string
    {
        $gameModel = $this->getGameModel();

        return (new $gameModel)->getTable();
    }

    protected function sportSlug(): ?string
    {
        $gamesTable = $this->gamesTable();
        if (! str_ends_with($gamesTable, '_games')) {
            return null;
        }

        return (string) substr($gamesTable, 0, -strlen('_games'));
    }

    /**
     * @return array<int, int|string>
     */
    protected function resolveSeasonTypeCandidates(?string $requested): array
    {
        $requested = trim((string) $requested);
        if ($requested === '') {
            return [];
        }

        $sportSlug = $this->sportSlug();
        if (! $sportSlug) {
            return [$requested];
        }

        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [$requested];

        if (is_numeric($requested)) {
            $code = (int) $requested;
            $candidates[] = $code;
            $matchedKey = array_search($code, $typesByKey, true);

            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = (string) $typeNames[$matchedKey];
                }
            }
        } else {
            if (isset($typesByKey[$requested])) {
                $resolvedCode = $typesByKey[$requested];
                $candidates[] = $resolvedCode;
                $candidates[] = (string) $resolvedCode;
            }

            $matchedKey = array_search($requested, $typeNames, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typesByKey[$matchedKey])) {
                    $resolvedCode = $typesByKey[$matchedKey];
                    $candidates[] = $resolvedCode;
                    $candidates[] = (string) $resolvedCode;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }

    /**
     * @return array<int, int|string>
     */
    protected function requestedSeasonTypeCandidates(Request $request): array
    {
        if (! $request->filled('season_type') || ! $this->hasGameSeasonTypeColumn()) {
            return [];
        }

        return $this->resolveSeasonTypeCandidates((string) $request->input('season_type'));
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
            ->tap(fn ($query) => $this->applySeasonFiltersToStatsQuery($query, $request))
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
            ->tap(fn ($query) => $this->applySeasonFiltersToStatsQuery($query, $request))
            ->tap(fn ($query) => $this->applyByPlayerOrdering($query, $request))
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

    public function availableSeasons(): JsonResponse
    {
        if (! $this->hasGameSeasonColumn()) {
            return response()->json(['data' => []]);
        }

        $playerStatModel = $this->getPlayerStatModel();
        $gameModel = $this->getGameModel();
        $playerStatInstance = new $playerStatModel;
        $gameInstance = new $gameModel;
        $gameTable = $gameInstance->getTable();
        $playerStatTable = $playerStatInstance->getTable();
        $seasonColumn = $this->getGameSeasonColumn();

        $seasons = $playerStatModel::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$playerStatTable}.game_id")
            ->whereNotNull("{$gameTable}.{$seasonColumn}")
            ->select("{$gameTable}.{$seasonColumn}")
            ->distinct()
            ->orderByDesc("{$gameTable}.{$seasonColumn}")
            ->pluck("{$gameTable}.{$seasonColumn}");

        return response()->json(['data' => $seasons]);
    }
}
