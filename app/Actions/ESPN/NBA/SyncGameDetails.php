<?php

namespace App\Actions\ESPN\NBA;

use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\NBA\Game;
use App\Models\NBA\Play;
use App\Models\NBA\Team;
use App\Services\ESPN\NBA\EspnService;

class SyncGameDetails
{
    public function __construct(
        protected EspnService $espnService,
        protected SyncPlayerStats $syncPlayerStats,
        protected SyncTeamStats $syncTeamStats
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

        $playsSynced = $this->syncPlays($gameData, $game);
        $statsSynced = $this->syncPlayerStats->execute($gameData, $game);
        $teamStatsSynced = $this->syncTeamStats->execute($gameData, $game);

        return ['plays' => $playsSynced, 'player_stats' => $statsSynced, 'team_stats' => $teamStatsSynced];
    }

    protected function syncPlays(array $gameData, Game $game): int
    {
        if (! isset($gameData['plays'])) {
            return 0;
        }

        // Delete existing plays for this game to avoid duplicates
        Play::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($gameData['plays'] as $index => $playData) {
            // Skip plays without an ID
            if (empty($playData['id'])) {
                continue;
            }

            $dto = BasketballPlayData::fromEspnResponse($playData, $index);

            $playAttributes = $dto->toArray();
            $playAttributes['game_id'] = $game->id;

            // Set possession team if available
            if ($dto->possessionTeamEspnId) {
                $possessionTeam = Team::query()->where('espn_id', $dto->possessionTeamEspnId)->first();
                if ($possessionTeam) {
                    $playAttributes['possession_team_id'] = $possessionTeam->id;
                }
            }

            Play::create($playAttributes);

            $synced++;
        }

        return $synced;
    }
}
