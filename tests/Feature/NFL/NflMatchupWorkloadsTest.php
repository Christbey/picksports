<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\NFL\Matchups\NflMatchupWorkloads;

it('counts attempts rushes and sacks only with complete paired official statistics', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $home = TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'passing_attempts' => 40, 'rushing_attempts' => 27, 'sacks_allowed' => 3]);
    $service = app(NflMatchupWorkloads::class);
    expect($service->forGames(collect([$game])))->toBe([]);
    $away = TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $game->away_team_id, 'passing_attempts' => 30, 'rushing_attempts' => 30, 'sacks_allowed' => 1]);
    $rows = $service->forGames(collect([$game]));
    expect($rows[$game->id][$game->home_team_id]['snaps'])->toBe(70)
        ->and($rows[$game->id][$game->away_team_id]['snaps'])->toBe(61);
    $away->update(['sacks_allowed' => null]);
    expect($service->forGames(collect([$game])))->toBe([]);
});
