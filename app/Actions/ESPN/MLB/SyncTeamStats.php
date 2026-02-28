<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\MLB\Concerns\ParsesMlbStatValues;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbBoxscoreTeams;
use App\Models\MLB\Game;
use App\Models\MLB\TeamStat;

class SyncTeamStats
{
    use ParsesMlbStatValues;
    use ResolvesMlbBoxscoreTeams;

    public function execute(array $gameData, Game $game): int
    {
        $teamSections = $this->boxscoreSection($gameData, 'teams');
        if ($teamSections === []) {
            return 0;
        }

        // Delete existing team stats for this game
        TeamStat::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($teamSections as $teamData) {
            $team = $this->resolveTeamFromBoxscore($teamData);
            if (! $team) {
                continue;
            }

            // Determine home/away from game record
            $teamType = ($team->id === $game->home_team_id) ? 'home' : 'away';

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            TeamStat::create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => $teamType,
                // Batting stats
                'runs' => $stats['batting']['runs'] ?? null,
                'hits' => $stats['batting']['hits'] ?? null,
                'errors' => $stats['fielding']['errors'] ?? null,
                'at_bats' => $stats['batting']['atBats'] ?? null,
                'doubles' => $stats['batting']['doubles'] ?? null,
                'triples' => $stats['batting']['triples'] ?? null,
                'home_runs' => $stats['batting']['homeRuns'] ?? null,
                'rbis' => $stats['batting']['RBIs'] ?? null,
                'walks' => $stats['batting']['walks'] ?? null,
                'strikeouts' => $stats['batting']['strikeouts'] ?? null,
                'stolen_bases' => $stats['batting']['stolenBases'] ?? null,
                'left_on_base' => $stats['batting']['runnersLeftOnBase'] ?? null,
                'batting_average' => $stats['batting']['avg'] ?? null,
                // Pitching stats
                'pitchers_used' => $stats['pitching']['gamesStarted'] ?? null,
                'innings_pitched' => $stats['pitching']['innings'] ?? null,
                'hits_allowed' => $stats['pitching']['hits'] ?? null,
                'runs_allowed' => $stats['pitching']['runs'] ?? null,
                'earned_runs' => $stats['pitching']['earnedRuns'] ?? null,
                'walks_allowed' => $stats['pitching']['walks'] ?? null,
                'strikeouts_pitched' => $stats['pitching']['strikeouts'] ?? null,
                'home_runs_allowed' => $stats['pitching']['homeRuns'] ?? null,
                'total_pitches' => $stats['pitching']['pitches'] ?? null,
                'era' => $stats['pitching']['ERA'] ?? null,
                // Fielding stats
                'putouts' => $stats['fielding']['putouts'] ?? null,
                'assists' => $stats['fielding']['assists'] ?? null,
                'double_plays' => $stats['fielding']['doublePlays'] ?? null,
                'passed_balls' => $stats['fielding']['passedBalls'] ?? null,
            ]);

            $synced++;
        }

        return $synced;
    }

    protected function parseTeamStats(array $statistics): array
    {
        $parsed = [
            'batting' => [],
            'pitching' => [],
            'fielding' => [],
            'records' => [],
        ];

        // Statistics are grouped by category (batting, pitching, fielding, records)
        foreach ($statistics as $category) {
            if (! isset($category['name']) || ! isset($category['stats']) || ! is_array($category['stats'])) {
                continue;
            }

            $categoryName = $category['name'];

            // Skip if not a known category
            if (! isset($parsed[$categoryName])) {
                continue;
            }

            // Loop through each stat in the category
            foreach ($category['stats'] as $stat) {
                if (! isset($stat['name']) || ! isset($stat['displayValue'])) {
                    continue;
                }

                $name = $stat['name'];
                $value = $stat['displayValue'];

                // Handle made-attempted format (e.g., "39-81")
                if (str_contains($value, '-') && is_numeric(str_replace('-', '', $value))) {
                    $parts = explode('-', $value);

                    if (count($parts) === 2) {
                        $parsed[$categoryName][$name.'Made'] = (int) $parts[0];
                        $parsed[$categoryName][$name.'Attempted'] = (int) $parts[1];
                    }
                } else {
                    // Handle single value stats
                    $parsed[$categoryName][$name] = $this->parseDisplayStatValue($value);
                }
            }
        }

        return $parsed;
    }
}
