<?php

namespace App\Actions\ESPN\CBB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;

class SyncTeamSchedule
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(string $teamEspnId): int
    {
        $response = $this->espnService->getSchedule($teamEspnId);

        if (! $response || ! isset($response['events'])) {
            return 0;
        }

        $synced = 0;

        foreach ($response['events'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($game);

            $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
            $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $gameDate = $game['date'] ? date('Y-m-d', strtotime($game['date'])) : null;

            // If game date is in the past and ESPN still returns STATUS_SCHEDULED,
            // override to STATUS_FINAL to prevent resetting completed games
            $status = $dto->status;
            if ($gameDate && $gameDate < now()->format('Y-m-d') && $status === 'STATUS_SCHEDULED') {
                $status = 'STATUS_FINAL';
            }

            // Check if game already exists
            $existingGame = Game::where('espn_event_id', $dto->espnEventId)->first();

            if ($existingGame) {
                // Only update if game is NOT final (don't overwrite completed game data)
                if (! in_array($existingGame->status, ['STATUS_FINAL', 'STATUS_FULL_TIME'])) {
                    // Only update fields that should change during the game
                    $existingGame->update([
                        'status' => $status,
                        'home_score' => $dto->homeScore,
                        'away_score' => $dto->awayScore,
                        'home_linescores' => $dto->homeLinescores,
                        'away_linescores' => $dto->awayLinescores,
                        'period' => $dto->period,
                        'game_clock' => $dto->gameClock,
                    ]);
                }
                // If game is final, don't touch it at all
            } else {
                // Create new game with all attributes
                Game::create([
                    'espn_event_id' => $dto->espnEventId,
                    'espn_uid' => $game['uid'] ?? null,
                    'season' => $dto->season,
                    'week' => $dto->week,
                    'season_type' => $dto->seasonType,
                    'game_date' => $gameDate,
                    'game_time' => $game['date'] ? date('H:i:s', strtotime($game['date'])) : null,
                    'name' => $dto->name,
                    'short_name' => $dto->shortName,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'home_score' => $dto->homeScore,
                    'away_score' => $dto->awayScore,
                    'home_linescores' => $dto->homeLinescores,
                    'away_linescores' => $dto->awayLinescores,
                    'status' => $status,
                    'period' => $dto->period,
                    'game_clock' => $dto->gameClock,
                    'venue_name' => $dto->venueName,
                    'venue_city' => $dto->venueCity,
                    'venue_state' => $dto->venueState,
                    'broadcast_networks' => $dto->broadcastNetworks,
                ]);
            }

            $synced++;
        }

        return $synced;
    }
}
