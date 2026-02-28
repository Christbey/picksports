<?php

namespace App\Actions\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = Model::class;

    public function __construct(
        protected BaseEspnService $espnService
    ) {}

    public function execute(string $teamEspnId, ?int $season = null): int
    {
        $response = $this->espnService->getSchedule($teamEspnId, $season);

        if (! $response || ! isset($response['events']) || ! is_array($response['events'])) {
            return 0;
        }

        $synced = 0;
        $gameModel = $this->gameModelClass();

        foreach ($response['events'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($game);

            [$homeTeam, $awayTeam] = $this->resolveTeams($dto, $game);

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $lookup = $this->gameLookupAttributes($dto);
            $existingGame = $gameModel::query()->where($lookup)->first();

            if ($existingGame) {
                if ($this->shouldUpdateExistingGame($existingGame, $dto, $game)) {
                    $existingGame->update(
                        $this->existingGameAttributes($dto, $game, $homeTeam, $awayTeam, $existingGame)
                    );
                }
            } else {
                $gameModel::query()->create($this->gameAttributes($dto, $game, $homeTeam, $awayTeam));
            }

            $synced++;
        }

        return $synced;
    }

    /**
     * @return array{0:?Model,1:?Model}
     */
    abstract protected function resolveTeams(GameData $dto, array $rawGame): array;

    /**
     * @return array<string,mixed>
     */
    abstract protected function gameAttributes(GameData $dto, array $rawGame, Model $homeTeam, Model $awayTeam): array;

    /**
     * @return array<string,mixed>
     */
    protected function existingGameAttributes(
        GameData $dto,
        array $rawGame,
        Model $homeTeam,
        Model $awayTeam,
        Model $existingGame
    ): array {
        return $this->gameAttributes($dto, $rawGame, $homeTeam, $awayTeam);
    }

    /**
     * @return array<string,mixed>
     */
    protected function gameLookupAttributes(GameData $dto): array
    {
        return ['espn_event_id' => $dto->espnEventId];
    }

    protected function shouldUpdateExistingGame(Model $existingGame, GameData $dto, array $rawGame): bool
    {
        return true;
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined.');
        }

        return static::GAME_MODEL_CLASS;
    }
}
