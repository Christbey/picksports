<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('public predictions expose verified stored kickoff separately from calendar date', function () {
    $this->travelTo(now()->setDate(2026, 11, 26)->startOfDay());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-11-27', 'game_time' => '19:45:00', 'status' => config('nba.statuses.scheduled')]);
    Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => 0, 'predicted_total' => 200, 'win_probability' => 0, 'confidence_score' => 0]);
    $this->withoutVite()->get('/nba')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('featuredPredictions', 1)
        ->where('featuredPredictions.0.starts_at', '2026-11-27T19:45:00+00:00')
        ->where('featuredPredictions.0.home_win_probability', 0));
});

test('public injury source clock stays unqualified and local storage is labeled separately', function () {
    $team = Team::factory()->create();
    $player = Player::factory()->for($team)->create();
    PlayerInjury::query()->create(['team_id' => $team->id, 'player_id' => $player->id, 'injury_key' => 'synthetic-source-clock', 'status' => 'Out', 'source_updated_at' => '2026-11-26 10:00:00', 'is_active' => true]);
    $this->withoutVite()->get('/nba')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('injuries.recent', 1)
        ->where('injuries.recent.0.source_updated_at', null)
        ->where('injuries.recent.0.source_updated_at_raw', '2026-11-26 10:00:00')
        ->where('injuries.recent.0.source_clock_status', 'unverified_storage_clock')
        ->where('injuries.recent.0.last_stored_at', fn ($value) => is_string($value)));
});
