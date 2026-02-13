<?php

namespace App\Actions\ESPN\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;

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

            // Baseball has multiple statistics sections (batting, pitching, fielding)
            if (! isset($teamData['statistics'])) {
                continue;
            }

            foreach ($teamData['statistics'] as $statSection) {
                $statType = strtolower($statSection['type'] ?? 'batting');

                if (! isset($statSection['athletes'])) {
                    continue;
                }

                foreach ($statSection['athletes'] as $athleteData) {
                    $playerEspnId = $athleteData['athlete']['id'] ?? null;

                    if (! $playerEspnId) {
                        continue;
                    }

                    $player = Player::query()->where('espn_id', $playerEspnId)->first();

                    if (! $player) {
                        continue;
                    }

                    $stats = $athleteData['stats'] ?? [];

                    // Parse stats based on type
                    $statData = match ($statType) {
                        'batting' => $this->parseBattingStats($stats),
                        'pitching' => $this->parsePitchingStats($stats),
                        'fielding' => $this->parseFieldingStats($stats),
                        default => [],
                    };

                    if (empty($statData)) {
                        continue;
                    }

                    PlayerStat::create([
                        'player_id' => $player->id,
                        'game_id' => $game->id,
                        'team_id' => $team->id,
                        'stat_type' => $statType,
                        ...$statData,
                    ]);

                    $synced++;
                }
            }
        }

        return $synced;
    }

    protected function parseBattingStats(array $stats): array
    {
        // Stats array typically: [AB, R, H, HR, RBI, BB, K, SB, AVG, OBP, SLG]
        // But order may vary, so we map by index
        return [
            'at_bats' => isset($stats[0]) && is_numeric($stats[0]) ? (int) $stats[0] : null,
            'runs' => isset($stats[1]) && is_numeric($stats[1]) ? (int) $stats[1] : null,
            'hits' => isset($stats[2]) && is_numeric($stats[2]) ? (int) $stats[2] : null,
            'home_runs' => isset($stats[3]) && is_numeric($stats[3]) ? (int) $stats[3] : null,
            'rbis' => isset($stats[4]) && is_numeric($stats[4]) ? (int) $stats[4] : null,
            'walks' => isset($stats[5]) && is_numeric($stats[5]) ? (int) $stats[5] : null,
            'strikeouts' => isset($stats[6]) && is_numeric($stats[6]) ? (int) $stats[6] : null,
            'stolen_bases' => isset($stats[7]) && is_numeric($stats[7]) ? (int) $stats[7] : null,
            'batting_average' => isset($stats[8]) && is_numeric($stats[8]) ? (float) $stats[8] : null,
            'on_base_percentage' => isset($stats[9]) && is_numeric($stats[9]) ? (float) $stats[9] : null,
            'slugging_percentage' => isset($stats[10]) && is_numeric($stats[10]) ? (float) $stats[10] : null,
        ];
    }

    protected function parsePitchingStats(array $stats): array
    {
        // Stats array typically: [IP, H, R, ER, BB, K, HR, ERA, Pitches]
        return [
            'innings_pitched' => $stats[0] ?? null,
            'hits_allowed' => isset($stats[1]) && is_numeric($stats[1]) ? (int) $stats[1] : null,
            'runs_allowed' => isset($stats[2]) && is_numeric($stats[2]) ? (int) $stats[2] : null,
            'earned_runs' => isset($stats[3]) && is_numeric($stats[3]) ? (int) $stats[3] : null,
            'walks_allowed' => isset($stats[4]) && is_numeric($stats[4]) ? (int) $stats[4] : null,
            'strikeouts_pitched' => isset($stats[5]) && is_numeric($stats[5]) ? (int) $stats[5] : null,
            'home_runs_allowed' => isset($stats[6]) && is_numeric($stats[6]) ? (int) $stats[6] : null,
            'era' => isset($stats[7]) && is_numeric($stats[7]) ? (float) $stats[7] : null,
            'pitches_thrown' => isset($stats[8]) && is_numeric($stats[8]) ? (int) $stats[8] : null,
            'pitch_count' => isset($stats[8]) && is_numeric($stats[8]) ? (int) $stats[8] : null,
        ];
    }

    protected function parseFieldingStats(array $stats): array
    {
        // Stats array typically: [PO, A, E]
        return [
            'putouts' => isset($stats[0]) && is_numeric($stats[0]) ? (int) $stats[0] : null,
            'assists' => isset($stats[1]) && is_numeric($stats[1]) ? (int) $stats[1] : null,
            'errors' => isset($stats[2]) && is_numeric($stats[2]) ? (int) $stats[2] : null,
        ];
    }
}
