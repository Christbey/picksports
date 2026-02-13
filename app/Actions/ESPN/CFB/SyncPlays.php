<?php

namespace App\Actions\ESPN\CFB;

use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;

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

        // Get the first competition ID from the game
        $gameData = $this->espnService->getGame($eventId);

        if (! $gameData || ! isset($gameData['competitions'][0]['id'])) {
            return 0;
        }

        $competitionId = $gameData['competitions'][0]['id'];

        $response = $this->espnService->getPlays($eventId, $competitionId);

        if (! $response || ! isset($response['items'])) {
            return 0;
        }

        // Delete existing plays for this game to avoid duplicates
        Play::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($response['items'] as $index => $playData) {
            $dto = FootballPlayData::fromEspnResponse($playData, $index);

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
