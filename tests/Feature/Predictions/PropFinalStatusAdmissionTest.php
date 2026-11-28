<?php

use App\Actions\GradePlayerProps;

it('grades actual linked player statistics for both admitted final states', function (string $sport, string $status) {
    $prefix = 'App\\Models\\'.$sport.'\\';
    $teamClass = $prefix.'Team';
    $gameClass = $prefix.'Game';
    $playerClass = $prefix.'Player';
    $statClass = $prefix.'PlayerStat';
    $propClass = $prefix.'PlayerProp';
    $home = $teamClass::factory()->create();
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $teamClass::factory(),
        'status' => $status, 'home_score' => 28, 'away_score' => 21, 'season' => 2026]);
    $player = $playerClass::factory()->create(['team_id' => $home->id]);
    $statClass::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $home->id,
        ...($sport === 'NFL' ? ['receptions' => 4] : ['points' => 28])]);
    $prop = $propClass::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Synthetic Player',
        'market' => $sport === 'NFL' ? 'player_receptions' : 'player_points', 'line' => 2.5]);
    $key = $sport === 'NFL' ? 'americanfootball_nfl' : 'basketball_nba';
    expect(app(GradePlayerProps::class)->executeForGame($key, $game->id)['graded'])->toBe(1)
        ->and((float) $prop->fresh()->actual_value)->toBe($sport === 'NFL' ? 4.0 : 28.0)
        ->and($prop->fresh()->graded_at)->not->toBeNull();
})->with(['NBA', 'NFL'])->with(['STATUS_FINAL', 'STATUS_FULL_TIME']);
