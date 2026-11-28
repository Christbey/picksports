<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Services\GameFinalizationDispatcher;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\EspnBasketballNeutralSite;
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

        if (! $gameData || (data_get($gameData, 'header.id') !== null && (string) data_get($gameData, 'header.id') !== $eventId)
            || ! EspnBasketballNeutralSite::valid($this->sportKey(), $gameData)) {
            return $this->emptyResult();
        }

        $gameUpdated = false;
        $previousStatus = null;
        app(SportEventIdentitySynchronizer::class)->mutateAndSync($this->sportKey(), $game,
            function (Model $locked) use ($gameData, &$gameUpdated, &$previousStatus): void {
                $previousStatus = (string) $locked->status;
                EspnBasketballNeutralSite::persist($this->sportKey(), $locked, $gameData);
                $gameUpdated = $this->updateGame($gameData, $locked);
            }, EspnBasketballNeutralSite::reported($this->sportKey(), $gameData));
        $game = $game->fresh();
        if ($gameUpdated) {
            $game->getConnection()->afterCommit(fn () => app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, $previousStatus));
        }

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

    protected function sportKey(): string
    {
        $parts = explode('\\', $this->gameModelClass());

        return strtolower($parts[2] ?? '');
    }
}
