<?php

namespace App\Actions\ESPN\NFL;

use App\Models\NFL\Game;
use App\Services\ESPN\NFL\EspnService;

class SyncGameDetails
{
    public function __construct(
        protected EspnService $espnService,
        protected SyncPlayerStats $syncPlayerStats,
        protected SyncTeamStats $syncTeamStats,
        protected SyncPlays $syncPlays
    ) {}

    public function execute(string $eventId): array
    {
        $game = Game::query()->where('espn_event_id', $eventId)->first();

        if (! $game) {
            return ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0];
        }

        // Get the game summary which includes plays and boxscore
        $gameData = $this->espnService->getGame($eventId);

        if (! $gameData) {
            return ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0];
        }

        $playsSynced = $this->syncPlays->execute($eventId);
        $playerStatsSynced = $this->syncPlayerStats->execute($gameData, $game);
        $teamStatsSynced = $this->syncTeamStats->execute($gameData, $game);

        return ['plays' => $playsSynced, 'player_stats' => $playerStatsSynced, 'team_stats' => $teamStatsSynced];
    }
}
