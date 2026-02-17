<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\CBB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;

class SyncGamesFromScoreboard
{
    public function __construct(
        protected EspnService $espnService,
        protected ?UpdateLivePrediction $updateLivePrediction = null
    ) {
        $this->updateLivePrediction ??= new UpdateLivePrediction;
    }

    public function execute(string $date): int
    {
        $response = $this->espnService->getScoreboard($date);

        if (! $response || ! isset($response['events'])) {
            return 0;
        }

        $synced = 0;

        foreach ($response['events'] as $eventData) {
            if (empty($eventData['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($eventData);

            $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
            $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $gameAttributes = [
                'espn_event_id' => $dto->espnEventId,
                'espn_uid' => $eventData['uid'] ?? null,
                'season' => $dto->season,
                'week' => $dto->week,
                'season_type' => $dto->seasonType,
                'game_date' => $eventData['date'] ? date('Y-m-d', strtotime($eventData['date'])) : null,
                'game_time' => $eventData['date'] ? date('H:i:s', strtotime($eventData['date'])) : null,
                'name' => $dto->name,
                'short_name' => $dto->shortName,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'home_score' => $dto->homeScore,
                'away_score' => $dto->awayScore,
                'home_linescores' => $dto->homeLinescores,
                'away_linescores' => $dto->awayLinescores,
                'status' => $dto->status,
                'period' => $dto->period,
                'game_clock' => $dto->gameClock,
                'venue_name' => $dto->venueName,
                'venue_city' => $dto->venueCity,
                'venue_state' => $dto->venueState,
                'broadcast_networks' => $dto->broadcastNetworks,
            ];

            $existingGame = Game::where('espn_event_id', $dto->espnEventId)->first();

            if ($existingGame) {
                // Don't overwrite completed game data
                if (! in_array($existingGame->status, ['STATUS_FINAL', 'STATUS_FULL_TIME'])) {
                    $existingGame->update($gameAttributes);
                }
                $gameModel = $existingGame;
            } else {
                $gameModel = Game::create($gameAttributes);
            }

            // Update live predictions for in-progress games, clear stale data for finished games
            $this->updateLivePrediction->execute($gameModel);

            $synced++;
        }

        return $synced;
    }
}
