<?php

use App\Models\CFB\Game;

function cfbCompleteBox(Game $game): array
{
    $result = ['boxscore' => ['teams' => [], 'players' => []]];
    foreach ([$game->homeTeam, $game->awayTeam] as $index => $team) {
        $result['boxscore']['teams'][] = ['team' => ['id' => $team->espn_id], 'statistics' => [
            ['name' => 'totalYards', 'displayValue' => '513'], ['name' => 'netPassingYards', 'displayValue' => '300'], ['name' => 'rushingYards', 'displayValue' => '213']]];
        $result['boxscore']['players'][] = ['team' => ['id' => $team->espn_id], 'statistics' => [
            ['name' => 'passing', 'labels' => ['C/ATT', 'YDS'], 'athletes' => [['athlete' => ['id' => (string) (90001 + $index), 'displayName' => 'Same Name'], 'stats' => ['20/30', '300']]]],
            ['name' => 'rushing', 'labels' => ['CAR', 'YDS'], 'athletes' => [['athlete' => ['id' => (string) (90001 + $index), 'displayName' => 'Same Name'], 'stats' => ['4', '244']], ['athlete' => ['id' => '-1', 'displayName' => 'Team'], 'stats' => ['1', '-31']]]]]];
    }

    return $result;
}
