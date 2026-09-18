<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('includes active named injuries on NFL game details and preserves the local calendar date', function () {
    Sanctum::actingAs(User::factory()->create());
    config()->set('trends.timezones.nfl.display', 'America/New_York');
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2026-09-18', 'game_time' => '00:15:00',
    ]);
    $player = Player::factory()->create(['team_id' => $away->id, 'full_name' => 'Test Lineman']);
    foreach ([true, false] as $active) {
        PlayerInjury::create([
            'team_id' => $away->id, 'player_id' => $player->id,
            'injury_key' => 'report-'.(int) $active, 'status' => 'Out',
            'is_active' => $active, 'source_updated_at' => '2026-09-17 12:00:00',
        ]);
    }

    $this->getJson("/api/v2/sports/nfl/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.game_date', '2026-09-17')
        ->assertJsonPath('data.game_time', '20:15:00')
        ->assertJsonPath('data.away_team.active_injuries_count', 1)
        ->assertJsonCount(1, 'data.away_team.active_injuries')
        ->assertJsonPath('data.away_team.active_injuries.0.player_name', 'Test Lineman')
        ->assertJsonPath('data.away_team.active_injuries.0.status', 'Out')
        ->assertJsonPath('data.away_team.active_injuries.0.is_active', true)
        ->assertJsonPath('data.home_team.active_injuries_count', 0)
        ->assertJsonPath('data.home_team.active_injuries', []);

    $this->getJson('/api/v2/sports/nfl/games')
        ->assertOk()
        ->assertJsonMissingPath('data.0.away_team.active_injuries');
});
