<?php

use Audit\NflSeasonalWeightingStudy;

// Accept a previously authorized read-only export. No database or network access.
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/NflSeasonalWeightingStudy.php';

if (count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/audits/RunNflSeasonalWeightingStudy.php <games-and-config-export.json>\n");
    exit(1);
}
$data = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$games = array_map(fn ($game) => [
    'id' => $game['id'], 'date' => substr($game['game_date'], 0, 10), 'season' => (int) $game['season'],
    'type' => match ((string) $game['season_type']) {
        '2', 'regular' => '2', '3', 'postseason' => '3', default => '1'
    },
    'week' => (int) $game['week'], 'home' => $game['home_team_id'], 'away' => $game['away_team_id'],
    'neutral' => (bool) $game['neutral_site'], 'actual' => (float) $game['home_score'] - (float) $game['away_score'],
    'home_qb_name' => $game['home_qb_name'], 'away_qb_name' => $game['away_qb_name'],
], $data['games']);
echo json_encode(NflSeasonalWeightingStudy::analyze($games, $data['config']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
