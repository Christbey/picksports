<?php

namespace App\Actions\ESPN\NFL;

use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;

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

        // ESPN typically uses the same ID for event and competition
        $competitionId = $eventId;

        $response = $this->espnService->getPlays($eventId, $competitionId);

        if (! $response || ! isset($response['items'])) {
            return 0;
        }

        // Delete existing plays for this game to avoid duplicates
        Play::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($response['items'] as $index => $playData) {
            // Skip plays without an ID
            if (empty($playData['id'])) {
                continue;
            }

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
