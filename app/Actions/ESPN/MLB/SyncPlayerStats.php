<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\MLB\Concerns\ParsesMlbStatValues;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbBoxscoreTeams;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;

class SyncPlayerStats
{
    use ParsesMlbStatValues;
    use ResolvesMlbBoxscoreTeams;

    public function execute(array $gameData, Game $game): int
    {
        $playerSections = $this->boxscoreSection($gameData, 'players');
        if ($playerSections === []) {
            return 0;
        }

        // Delete existing stats for this game to avoid duplicates
        PlayerStat::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($playerSections as $teamData) {
            $team = $this->resolveTeamFromBoxscore($teamData);
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
            'at_bats' => $this->intAt($stats, 0),
            'runs' => $this->intAt($stats, 1),
            'hits' => $this->intAt($stats, 2),
            'home_runs' => $this->intAt($stats, 3),
            'rbis' => $this->intAt($stats, 4),
            'walks' => $this->intAt($stats, 5),
            'strikeouts' => $this->intAt($stats, 6),
            'stolen_bases' => $this->intAt($stats, 7),
            'batting_average' => $this->floatAt($stats, 8),
            'on_base_percentage' => $this->floatAt($stats, 9),
            'slugging_percentage' => $this->floatAt($stats, 10),
        ];
    }

    protected function parsePitchingStats(array $stats): array
    {
        // Stats array typically: [IP, H, R, ER, BB, K, HR, ERA, Pitches]
        return [
            'innings_pitched' => $stats[0] ?? null,
            'hits_allowed' => $this->intAt($stats, 1),
            'runs_allowed' => $this->intAt($stats, 2),
            'earned_runs' => $this->intAt($stats, 3),
            'walks_allowed' => $this->intAt($stats, 4),
            'strikeouts_pitched' => $this->intAt($stats, 5),
            'home_runs_allowed' => $this->intAt($stats, 6),
            'era' => $this->floatAt($stats, 7),
            'pitches_thrown' => $this->intAt($stats, 8),
            'pitch_count' => $this->intAt($stats, 8),
        ];
    }

    protected function parseFieldingStats(array $stats): array
    {
        // Stats array typically: [PO, A, E]
        return [
            'putouts' => $this->intAt($stats, 0),
            'assists' => $this->intAt($stats, 1),
            'errors' => $this->intAt($stats, 2),
        ];
    }
}
