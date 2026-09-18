<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('protects live snapshots and rejects other sports', function () {
    $this->getJson('/api/v2/sports/nfl/games/1/live-snapshot')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cfb/games/1/live-snapshot')->assertNotFound();
});

it('returns a coherent provisional live snapshot without writing prediction records', function () {
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_IN_PROGRESS', 'season_type' => '2', 'period' => 2, 'game_clock' => '0:39', 'home_score' => 27, 'away_score' => 7]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 7.3, 'predicted_total' => 45, 'win_probability' => .72, 'live_win_probability' => .55]);
    $before = $prediction->fresh()->getRawOriginal();

    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/live-snapshot")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.game.home_score', 27)
        ->assertJsonPath('data.game.game_clock', '0:39')
        ->assertJsonPath('data.projection.live_seconds_remaining', 1839)
        ->assertJsonPath('data.provisional', true)
        ->assertJsonStructure(['data' => ['source_updated_at', 'generated_at', 'warning']]);

    expect($prediction->fresh()->getRawOriginal())->toBe($before);
});

it('does not expose a projection for final games or incomplete game state', function (string $status, ?string $clock) {
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => $status, 'period' => 4, 'game_clock' => $clock, 'home_score' => 27, 'away_score' => 7]);
    Prediction::factory()->create(['game_id' => $game->id, 'live_win_probability' => .9]);
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/live-snapshot")
        ->assertOk()->assertJsonPath('data.projection', null)->assertJsonPath('data.game.status', $status);
})->with([['STATUS_FINAL', '0:00'], ['STATUS_IN_PROGRESS', null], ['STATUS_IN_PROGRESS', 'bad']]);
