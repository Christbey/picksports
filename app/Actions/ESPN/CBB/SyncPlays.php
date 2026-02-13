<?php

namespace App\Actions\ESPN\CBB;

use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;

class SyncPlays
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(string $eventId): int
    {
        $game = Game::query()->where('espn_event_id', $eventId)->first();

        if (! $game) {
            return 0;
        }

        // For CBB, plays are included directly in the game summary response
        $gameData = $this->espnService->getGame($eventId);

        if (! $gameData) {
            return 0;
        }

        $playsSynced = 0;

        // Sync plays if available
        if (isset($gameData['plays'])) {
            // Delete existing plays for this game to avoid duplicates
            Play::query()->where('game_id', $game->id)->delete();

            foreach ($gameData['plays'] as $index => $playData) {
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

                $playsSynced++;
            }
        }

        return $playsSynced;
    }
}
