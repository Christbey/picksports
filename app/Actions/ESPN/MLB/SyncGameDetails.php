<?php

namespace App\Actions\ESPN\MLB;

use App\DataTransferObjects\ESPN\BaseballPlayData;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;

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
            return ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0, 'game_updated' => false];
        }

        // Get the game summary which includes plays and boxscore
        $gameData = $this->espnService->getGame($eventId);

        if (! $gameData) {
            return ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0, 'game_updated' => false];
        }

        // Update game with linescores and status
        $this->updateGameDetails($gameData, $game);

        $playsSynced = $this->syncPlays($gameData, $game);
        $statsSynced = $this->syncPlayerStats->execute($gameData, $game);
        $teamStatsSynced = $this->syncTeamStats->execute($gameData, $game);

        return ['plays' => $playsSynced, 'player_stats' => $statsSynced, 'team_stats' => $teamStatsSynced, 'game_updated' => true];
    }

    protected function updateGameDetails(array $gameData, Game $game): void
    {
        $competition = $gameData['header']['competitions'][0] ?? null;

        if (! $competition) {
            return;
        }

        $updateData = [];

        // Update status
        if (isset($competition['status']['type']['name'])) {
            $updateData['status'] = $competition['status']['type']['name'];
        }

        // Find home and away competitors
        $homeCompetitor = null;
        $awayCompetitor = null;

        foreach ($competition['competitors'] ?? [] as $competitor) {
            if ($competitor['homeAway'] === 'home') {
                $homeCompetitor = $competitor;
            } elseif ($competitor['homeAway'] === 'away') {
                $awayCompetitor = $competitor;
            }
        }

        // Update scores (score is a string in the header)
        if ($homeCompetitor && isset($homeCompetitor['score'])) {
            $updateData['home_score'] = $homeCompetitor['score'];
        }

        if ($awayCompetitor && isset($awayCompetitor['score'])) {
            $updateData['away_score'] = $awayCompetitor['score'];
        }

        // Update linescores
        if ($homeCompetitor && isset($homeCompetitor['linescores']) && is_array($homeCompetitor['linescores'])) {
            $homeLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $homeCompetitor['linescores']);
            $updateData['home_linescores'] = json_encode($homeLinescores);
        }

        if ($awayCompetitor && isset($awayCompetitor['linescores']) && is_array($awayCompetitor['linescores'])) {
            $awayLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $awayCompetitor['linescores']);
            $updateData['away_linescores'] = json_encode($awayLinescores);
        }

        // Update hits and errors
        if ($homeCompetitor) {
            if (isset($homeCompetitor['hits'])) {
                $updateData['home_hits'] = $homeCompetitor['hits'];
            }
            if (isset($homeCompetitor['errors'])) {
                $updateData['home_errors'] = $homeCompetitor['errors'];
            }
        }

        if ($awayCompetitor) {
            if (isset($awayCompetitor['hits'])) {
                $updateData['away_hits'] = $awayCompetitor['hits'];
            }
            if (isset($awayCompetitor['errors'])) {
                $updateData['away_errors'] = $awayCompetitor['errors'];
            }
        }

        if (! empty($updateData)) {
            $game->update($updateData);
        }
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

            $dto = BaseballPlayData::fromEspnResponse($playData, $index);

            $playAttributes = $dto->toArray();
            $playAttributes['game_id'] = $game->id;

            // Set batting team if available
            if ($dto->battingTeamEspnId) {
                $battingTeam = Team::query()->where('espn_id', $dto->battingTeamEspnId)->first();
                if ($battingTeam) {
                    $playAttributes['batting_team_id'] = $battingTeam->id;
                }
            }

            // Set pitching team if available
            if ($dto->pitchingTeamEspnId) {
                $pitchingTeam = Team::query()->where('espn_id', $dto->pitchingTeamEspnId)->first();
                if ($pitchingTeam) {
                    $playAttributes['pitching_team_id'] = $pitchingTeam->id;
                }
            }

            Play::create($playAttributes);

            $synced++;
        }

        return $synced;
    }
}
