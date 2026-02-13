<?php

namespace App\Actions\ESPN\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\Team;

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

            // The statistics array contains the stat definitions and athlete data
            if (! isset($teamData['statistics'][0]['athletes'])) {
                continue;
            }

            $statsDefinition = $teamData['statistics'][0];
            $athletes = $statsDefinition['athletes'];

            foreach ($athletes as $athleteData) {
                $playerEspnId = $athleteData['athlete']['id'] ?? null;

                if (! $playerEspnId) {
                    continue;
                }

                $player = Player::query()->where('espn_id', $playerEspnId)->first();

                if (! $player) {
                    continue;
                }

                // Skip players who didn't play
                if (($athleteData['didNotPlay'] ?? false) || empty($athleteData['stats'])) {
                    continue;
                }

                // Parse the stats array
                $stats = $athleteData['stats'] ?? [];

                // Stats come as strings: ["19", "6", "3-7", "0-1", "0-0", "1", "1", "3", "0", "0", "0", "1", "1"]
                // Index mapping: 0: minutes, 1: points, 2: FG, 3: 3PT, 4: FT, 5: REB, 6: AST, 7: TO, 8: STL, 9: BLK, 10: OREB, 11: DREB, 12: PF
                $fgParts = isset($stats[2]) ? explode('-', $stats[2]) : [0, 0];
                $threeParts = isset($stats[3]) ? explode('-', $stats[3]) : [0, 0];
                $ftParts = isset($stats[4]) ? explode('-', $stats[4]) : [0, 0];

                PlayerStat::create([
                    'player_id' => $player->id,
                    'game_id' => $game->id,
                    'team_id' => $team->id,
                    'minutes_played' => $stats[0] ?? null,
                    'points' => isset($stats[1]) ? (int) $stats[1] : 0,
                    'field_goals_made' => isset($fgParts[0]) ? (int) $fgParts[0] : 0,
                    'field_goals_attempted' => isset($fgParts[1]) ? (int) $fgParts[1] : 0,
                    'three_point_made' => isset($threeParts[0]) ? (int) $threeParts[0] : 0,
                    'three_point_attempted' => isset($threeParts[1]) ? (int) $threeParts[1] : 0,
                    'free_throws_made' => isset($ftParts[0]) ? (int) $ftParts[0] : 0,
                    'free_throws_attempted' => isset($ftParts[1]) ? (int) $ftParts[1] : 0,
                    'rebounds_total' => isset($stats[5]) ? (int) $stats[5] : 0,
                    'assists' => isset($stats[6]) ? (int) $stats[6] : 0,
                    'turnovers' => isset($stats[7]) ? (int) $stats[7] : 0,
                    'steals' => isset($stats[8]) ? (int) $stats[8] : 0,
                    'blocks' => isset($stats[9]) ? (int) $stats[9] : 0,
                    'rebounds_offensive' => isset($stats[10]) ? (int) $stats[10] : 0,
                    'rebounds_defensive' => isset($stats[11]) ? (int) $stats[11] : 0,
                    'fouls' => isset($stats[12]) ? (int) $stats[12] : 0,
                ]);

                $synced++;
            }
        }

        return $synced;
    }
}
