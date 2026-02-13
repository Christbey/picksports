<?php

namespace App\Actions\ESPN\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;

class SyncPlayerStats
{
    public function execute(array $gameData, Game $game): int
    {
        if (! isset($gameData['boxscore']['players'])) {
            return 0;
        }

        // Delete existing stats for this game to avoid duplicates
        PlayerStat::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($gameData['boxscore']['players'] as $teamData) {
            $teamEspnId = $teamData['team']['id'] ?? null;

            if (! $teamEspnId) {
                continue;
            }

            $team = Team::query()->where('espn_id', $teamEspnId)->first();

            if (! $team) {
                continue;
            }

            // Process each stat category (passing, rushing, receiving, defensive, kicking, etc.)
            if (! isset($teamData['statistics'])) {
                continue;
            }

            foreach ($teamData['statistics'] as $statCategory) {
                $categoryName = $statCategory['name'] ?? '';
                $athletes = $statCategory['athletes'] ?? [];

                foreach ($athletes as $athleteData) {
                    $playerEspnId = $athleteData['athlete']['id'] ?? null;

                    if (! $playerEspnId) {
                        continue;
                    }

                    $player = Player::query()->where('espn_id', $playerEspnId)->first();

                    if (! $player) {
                        continue;
                    }

                    // Check if player stat record exists for this game
                    $playerStat = PlayerStat::query()
                        ->where('player_id', $player->id)
                        ->where('game_id', $game->id)
                        ->first();

                    if (! $playerStat) {
                        $playerStat = PlayerStat::create([
                            'player_id' => $player->id,
                            'game_id' => $game->id,
                            'team_id' => $team->id,
                        ]);
                    }

                    // Parse stats based on category
                    $stats = $athleteData['stats'] ?? [];
                    $this->updatePlayerStats($playerStat, $categoryName, $stats, $statCategory['labels'] ?? []);

                    $synced++;
                }
            }
        }

        return $synced;
    }

    protected function updatePlayerStats(PlayerStat $playerStat, string $category, array $stats, array $labels): void
    {
        $updates = [];

        // Map labels to stat values
        $mappedStats = [];
        foreach ($labels as $index => $label) {
            if (isset($stats[$index])) {
                $mappedStats[$label] = $stats[$index];
            }
        }

        // Parse stats based on category
        switch (strtolower($category)) {
            case 'passing':
                $updates = $this->parsePassingStats($mappedStats);
                break;
            case 'rushing':
                $updates = $this->parseRushingStats($mappedStats);
                break;
            case 'receiving':
                $updates = $this->parseReceivingStats($mappedStats);
                break;
        }

        if (! empty($updates)) {
            $playerStat->update($updates);
        }
    }

    protected function parsePassingStats(array $stats): array
    {
        $updates = [];

        // Parse C/ATT format (e.g., "15/25")
        if (isset($stats['C/ATT'])) {
            $parts = explode('/', $stats['C/ATT']);
            if (count($parts) === 2) {
                $updates['completions'] = (int) $parts[0];
                $updates['attempts'] = (int) $parts[1];
            }
        }

        $updates['passing_yards'] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates['passing_touchdowns'] = isset($stats['TD']) ? (int) $stats['TD'] : null;
        $updates['interceptions'] = isset($stats['INT']) ? (int) $stats['INT'] : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseRushingStats(array $stats): array
    {
        $updates = [];

        $updates['carries'] = isset($stats['CAR']) || isset($stats['ATT']) ? (int) ($stats['CAR'] ?? $stats['ATT'] ?? 0) : null;
        $updates['rushing_yards'] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates['rushing_touchdowns'] = isset($stats['TD']) ? (int) $stats['TD'] : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseReceivingStats(array $stats): array
    {
        $updates = [];

        $updates['receptions'] = isset($stats['REC']) ? (int) $stats['REC'] : null;
        $updates['receiving_yards'] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates['receiving_touchdowns'] = isset($stats['TD']) ? (int) $stats['TD'] : null;
        $updates['targets'] = isset($stats['TAR']) || isset($stats['TGTS']) ? (int) ($stats['TAR'] ?? $stats['TGTS'] ?? 0) : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }
}
