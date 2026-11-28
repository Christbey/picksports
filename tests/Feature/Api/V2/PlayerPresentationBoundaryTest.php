<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('keeps the path player authoritative over a contradictory prop query filter', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $first = Player::factory()->create(['team_id' => $home->id]);
    $other = Player::factory()->create(['team_id' => $away->id]);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    foreach ([$first, $other] as $player) {
        PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => $player->full_name, 'market' => 'player_points', 'bookmaker' => 'fixture', 'line' => 10, 'fetched_at' => now()]);
    }
    $this->getJson('/api/v2/sports/nba/players/'.$first->id.'/player-props?player_id='.$other->id)->assertOk()->assertJsonPath('data.0.player_id', $first->id)->assertJsonCount(1, 'data');
});

it('keeps the path game authoritative over a contradictory prop query filter', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $first = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $other = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    foreach ([$first, $other] as $game) {
        PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Fixture', 'market' => 'player_points', 'bookmaker' => 'fixture', 'line' => 10, 'fetched_at' => now()]);
    }
    $this->getJson('/api/v2/sports/nba/games/'.$first->id.'/player-props?game_id='.$other->id)->assertOk()->assertJsonPath('data.0.game_id', $first->id)->assertJsonCount(1, 'data');
});

it('reports explicitly loaded injury observations instead of claiming healthy from an omitted relation', function () {
    $player = Player::factory()->create(['team_id' => Team::factory()->create()->id]);
    $injury = $player->injuries()->create(['team_id' => $player->team_id, 'injury_key' => 'synthetic', 'is_active' => true, 'status' => 'Out', 'detail' => 'Synthetic observed injury']);
    $this->getJson('/api/v2/sports/nba/players/'.$player->id)->assertOk()->assertJsonPath('data.active_injuries.0.id', $injury->id)->assertJsonPath('data.active_injuries.0.status', 'Out');
});
