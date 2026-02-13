<?php

namespace App\Actions\ESPN\MLB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;

class SyncGames
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(int $season, int $seasonType, int $week): int
    {
        $response = $this->espnService->getGames($season, $seasonType, $week);

        if (! $response || ! isset($response['items'])) {
            return 0;
        }

        $synced = 0;

        foreach ($response['items'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($game);

            $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
            $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $gameAttributes = [
                'espn_event_id' => $dto->espnEventId,
                'espn_uid' => $game['uid'] ?? null,
                'season' => $dto->season,
                'week' => $dto->week,
                'season_type' => $dto->seasonType,
                'game_date' => $game['date'] ? date('Y-m-d', strtotime($game['date'])) : null,
                'game_time' => $game['date'] ? date('H:i:s', strtotime($game['date'])) : null,
                'name' => $dto->name,
                'short_name' => $dto->shortName,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'home_score' => $dto->homeScore,
                'away_score' => $dto->awayScore,
                'home_linescores' => $dto->homeLinescores,
                'away_linescores' => $dto->awayLinescores,
                'status' => $dto->status,
                'inning' => $dto->period,
                'inning_half' => null, // Will be populated from game details
                'balls' => null, // Will be populated from game details
                'strikes' => null, // Will be populated from game details
                'outs' => null, // Will be populated from game details
                'venue_name' => $dto->venueName,
                'venue_city' => $dto->venueCity,
                'venue_state' => $dto->venueState,
                'broadcast_networks' => $dto->broadcastNetworks,
            ];

            Game::updateOrCreate(
                ['espn_event_id' => $dto->espnEventId],
                $gameAttributes
            );

            $synced++;
        }

        return $synced;
    }
}
