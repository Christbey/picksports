<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\WCBB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;

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

        foreach ($response['events'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($game);

            // Get or create home team
            $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
            if (! $homeTeam) {
                $homeTeam = Team::create([
                    'espn_id' => $dto->homeTeamEspnId,
                    'school' => $game['competitions'][0]['competitors'][0]['team']['location'] ?? 'Unknown',
                    'mascot' => $game['competitions'][0]['competitors'][0]['team']['name'] ?? 'Unknown',
                    'abbreviation' => $game['competitions'][0]['competitors'][0]['team']['abbreviation'] ?? 'UNK',
                    'logo_url' => $game['competitions'][0]['competitors'][0]['team']['logo'] ?? null,
                ]);
            }

            // Get or create away team
            $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();
            if (! $awayTeam) {
                $awayTeam = Team::create([
                    'espn_id' => $dto->awayTeamEspnId,
                    'school' => $game['competitions'][0]['competitors'][1]['team']['location'] ?? 'Unknown',
                    'mascot' => $game['competitions'][0]['competitors'][1]['team']['name'] ?? 'Unknown',
                    'abbreviation' => $game['competitions'][0]['competitors'][1]['team']['abbreviation'] ?? 'UNK',
                    'logo_url' => $game['competitions'][0]['competitors'][1]['team']['logo'] ?? null,
                ]);
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
                'period' => $dto->period,
                'game_clock' => $dto->gameClock,
                'venue_name' => $dto->venueName,
                'venue_city' => $dto->venueCity,
                'venue_state' => $dto->venueState,
                'broadcast_networks' => $dto->broadcastNetworks,
            ];

            $existingGame = Game::where('espn_event_id', $dto->espnEventId)->first();

            if ($existingGame) {
                if (! in_array($existingGame->status, ['STATUS_FINAL', 'STATUS_FULL_TIME'])) {
                    $existingGame->update($gameAttributes);
                }
                $game = $existingGame;
            } else {
                $game = Game::create($gameAttributes);
            }

            // Update live predictions for in-progress games
            $this->updateLivePrediction->execute($game);

            $synced++;
        }

        return $synced;
    }
}
