<?php

namespace App\Actions\ESPN\WCBB;

use App\Models\WCBB\Game;
use App\Services\ESPN\WCBB\EspnService;

class SyncGameDetails
{
    public function __construct(
        protected EspnService $espnService,
        protected SyncPlayerStats $syncPlayerStats,
        protected SyncTeamStats $syncTeamStats,
        protected SyncPlays $syncPlays
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

        // Update game record with authoritative data from game summary endpoint
        $gameUpdated = $this->updateGameFromSummary($gameData, $game);

        $playsSynced = $this->syncPlays->execute($eventId);
        $playerStatsSynced = $this->syncPlayerStats->execute($gameData, $game);
        $teamStatsSynced = $this->syncTeamStats->execute($gameData, $game);

        return [
            'plays' => $playsSynced,
            'player_stats' => $playerStatsSynced,
            'team_stats' => $teamStatsSynced,
            'game_updated' => $gameUpdated,
        ];
    }

    protected function updateGameFromSummary(array $gameData, Game $game): bool
    {
        // Extract competition data from the game summary structure
        $header = $gameData['header'] ?? [];
        $competitions = $header['competitions'] ?? [];
        $competition = $competitions[0] ?? [];

        $competitors = $competition['competitors'] ?? [];
        $status = $competition['status'] ?? [];

        // Find home and away teams
        $homeTeam = collect($competitors)->firstWhere('homeAway', 'home');
        $awayTeam = collect($competitors)->firstWhere('homeAway', 'away');

        // Extract broadcast networks
        $broadcasts = $competition['broadcasts'] ?? [];
        $broadcastNetworks = collect($broadcasts)->pluck('names')->flatten()->toArray();

        // Normalize status
        $statusName = $status['type']['name'] ?? 'scheduled';

        // If already in STATUS_* format, use as-is
        if (str_starts_with($statusName, 'STATUS_')) {
            $normalizedStatus = $statusName;
        } else {
            // Otherwise normalize from lowercase format
            $statusMap = [
                'scheduled' => 'STATUS_SCHEDULED',
                'pre' => 'STATUS_SCHEDULED',
                'in progress' => 'STATUS_IN_PROGRESS',
                'in' => 'STATUS_IN_PROGRESS',
                'final' => 'STATUS_FINAL',
                'post' => 'STATUS_FINAL',
            ];
            $normalizedStatus = $statusMap[strtolower($statusName)] ?? 'STATUS_SCHEDULED';
        }

        // Update the game with complete data from the game summary endpoint
        // Note: ESPN returns scores as strings, so we cast to int
        $game->update([
            'status' => $normalizedStatus,
            'home_score' => isset($homeTeam['score']) ? (int) $homeTeam['score'] : null,
            'away_score' => isset($awayTeam['score']) ? (int) $awayTeam['score'] : null,
            'home_linescores' => $homeTeam['linescores'] ?? null,
            'away_linescores' => $awayTeam['linescores'] ?? null,
            'period' => isset($status['period']) ? (int) $status['period'] : null,
            'game_clock' => $status['displayClock'] ?? null,
            'broadcast_networks' => ! empty($broadcastNetworks) ? $broadcastNetworks : null,
        ]);

        return true;
    }
}
