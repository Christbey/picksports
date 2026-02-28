<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGameDetails
{
    protected const GAME_MODEL_CLASS = '';

    public function __construct(
        protected BaseEspnService $espnService,
        protected object $syncPlayerStats,
        protected object $syncTeamStats,
        protected object $syncPlays
    ) {}

    public function execute(string $eventId): array
    {
        $gameModel = $this->gameModelClass();
        $game = $gameModel::query()->where('espn_event_id', $eventId)->first();

        if (! $game) {
            return $this->emptyResult();
        }

        $gameData = $this->espnService->getGame($eventId);

        if (! $gameData) {
            return $this->emptyResult();
        }

        $gameUpdated = $this->updateGame($gameData, $game);

        $result = [
            'plays' => $this->syncPlays->execute($eventId),
            'player_stats' => $this->syncPlayerStats->execute($gameData, $game),
            'team_stats' => $this->syncTeamStats->execute($gameData, $game),
        ];

        if ($this->includeGameUpdatedFlag()) {
            $result['game_updated'] = $gameUpdated;
        }

        return $result;
    }

    protected function includeGameUpdatedFlag(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function updateGame(array $gameData, Model $game): bool
    {
        return false;
    }

    private function emptyResult(): array
    {
        $result = [
            'plays' => 0,
            'player_stats' => 0,
            'team_stats' => 0,
        ];

        if ($this->includeGameUpdatedFlag()) {
            $result['game_updated'] = false;
        }

        return $result;
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
}
