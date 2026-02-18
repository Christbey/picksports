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
        $scoreboardEventIds = [];

        foreach ($response['events'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $scoreboardEventIds[] = (string) $game['id'];

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
                $gameModel = $existingGame;
            } else {
                $gameModel = Game::create($gameAttributes);
            }

            // Update live predictions for in-progress games
            $this->updateLivePrediction->execute($gameModel);

            $synced++;
        }

        // ESPN drops completed games from the scoreboard response.
        // Fetch final scores individually for games still marked in-progress in the DB.
        $synced += $this->syncOrphanedInProgressGames($scoreboardEventIds);

        return $synced;
    }

    /**
     * Find games marked in-progress in the DB that ESPN dropped from the scoreboard
     * (because they finished) and fetch their final status individually.
     */
    protected function syncOrphanedInProgressGames(array $scoreboardEventIds): int
    {
        $orphanedGames = Game::query()
            ->whereIn('status', ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
            ->when($scoreboardEventIds, fn ($q) => $q->whereNotIn('espn_event_id', $scoreboardEventIds))
            ->get();

        $synced = 0;

        foreach ($orphanedGames as $game) {
            $gameData = $this->espnService->getGame($game->espn_event_id);

            if (! $gameData) {
                usleep(300_000);

                continue;
            }

            $this->updateGameFromSummary($gameData, $game);
            $this->updateLivePrediction->execute($game->refresh());
            $synced++;

            usleep(300_000);
        }

        return $synced;
    }

    /**
     * Update a game record from the ESPN game summary endpoint response.
     */
    protected function updateGameFromSummary(array $gameData, Game $game): void
    {
        $header = $gameData['header'] ?? [];
        $competitions = $header['competitions'] ?? [];
        $competition = $competitions[0] ?? [];

        $competitors = $competition['competitors'] ?? [];
        $status = $competition['status'] ?? [];

        $homeTeam = collect($competitors)->firstWhere('homeAway', 'home');
        $awayTeam = collect($competitors)->firstWhere('homeAway', 'away');

        $statusName = $status['type']['name'] ?? 'scheduled';
        $normalizedStatus = str_starts_with($statusName, 'STATUS_')
            ? $statusName
            : GameData::normalizeStatus($statusName);

        $game->update([
            'status' => $normalizedStatus,
            'home_score' => isset($homeTeam['score']) ? (int) $homeTeam['score'] : $game->home_score,
            'away_score' => isset($awayTeam['score']) ? (int) $awayTeam['score'] : $game->away_score,
            'home_linescores' => $homeTeam['linescores'] ?? $game->home_linescores,
            'away_linescores' => $awayTeam['linescores'] ?? $game->away_linescores,
            'period' => isset($status['period']) ? (int) $status['period'] : $game->period,
            'game_clock' => $status['displayClock'] ?? $game->game_clock,
        ]);
    }
}
