<?php

use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use Database\Factories\CbbGameFactory;
use Database\Factories\CbbTeamFactory;

test('cbb leaderboard endpoint includes estimated epa fields', function () {
    $team = CbbTeamFactory::new()->create();
    $opponent = CbbTeamFactory::new()->create();
    $player = Player::query()->create([
        'team_id' => $team->id,
        'espn_id' => 'cbb-test-player-1',
        'full_name' => 'CBB EPA Test Player',
        'first_name' => 'CBB',
        'last_name' => 'Player',
    ]);

    $games = CbbGameFactory::new()->count(12)->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    foreach ($games as $game) {
        PlayerStat::query()->create([
            'player_id' => $player->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'minutes_played' => '30',
            'points' => 20,
            'rebounds_total' => 5,
            'assists' => 8,
            'steals' => 2,
            'blocks' => 1,
            'turnovers' => 3,
            'field_goals_made' => 8,
            'field_goals_attempted' => 15,
            'free_throws_made' => 4,
            'free_throws_attempted' => 5,
        ]);
    }

    $response = $this->getJson('/api/v1/cbb/player-stats/leaderboard');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'player_id',
                    'estimated_epa_per_game',
                    'estimated_epa_per_36',
                ],
            ],
        ]);

    $entry = collect($response->json('data'))->firstWhere('player_id', $player->id);

    expect($entry)->not->toBeNull();
    expect((float) $entry['estimated_epa_per_game'])->toBe(14.17)
        ->and((float) $entry['estimated_epa_per_36'])->toBe(17.0);
});
