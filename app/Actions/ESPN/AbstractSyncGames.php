<?php

namespace App\Actions\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const UNIQUE_GAME_KEY = 'espn_event_id';

    public function __construct(protected BaseEspnService $espnService) {}

    protected function getUniqueGameKey(): string
    {
        return static::UNIQUE_GAME_KEY;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function getResponseItems(array $response): array
    {
        return is_array($response['items'] ?? null) ? $response['items'] : [];
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function gameDtoFromResponse(array $gameData): GameData
    {
        return GameData::fromEspnResponse($gameData);
    }

    /**
     * @param  array<string, mixed>  $gameData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(GameData $dto, array $gameData, Model $homeTeam, Model $awayTeam): array
    {
        $attributes = $dto->toArray();
        $attributes['home_team_id'] = $homeTeam->getKey();
        $attributes['away_team_id'] = $awayTeam->getKey();

        return $attributes;
    }

    protected function findTeamByEspnId(string $espnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $espnId)->first();
    }

    public function execute(int $season, int $seasonType, int $week): int
    {
        $response = $this->espnService->getGames($season, $seasonType, $week);

        if (! is_array($response)) {
            return 0;
        }

        $items = $this->getResponseItems($response);
        if ($items === []) {
            return 0;
        }

        $gameModel = $this->gameModelClass();
        $synced = 0;

        foreach ($items as $gameData) {
            if (empty($gameData['id'])) {
                continue;
            }

            $dto = $this->gameDtoFromResponse($gameData);
            $homeTeam = $this->findTeamByEspnId($dto->homeTeamEspnId);
            $awayTeam = $this->findTeamByEspnId($dto->awayTeamEspnId);

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $gameModel::updateOrCreate(
                [$this->getUniqueGameKey() => $dto->espnEventId],
                $this->buildGameAttributes($dto, $gameData, $homeTeam, $awayTeam)
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === '') {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === '') {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }
}
