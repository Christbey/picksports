<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns NFL page data using the same gated prediction contract', function () {
    config(['subscriptions.enforce_tiers' => false]);
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2026, 'season_type' => '2']);
    Prediction::factory()->create(['game_id' => $game->id]);
    TeamStat::create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'team_type' => 'home', 'rushing_yards' => 120]);
    $prediction = $this->getJson("/api/v2/sports/nfl/games/{$game->id}/prediction")->assertSuccessful()->json('data');
    $page = $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertSuccessful()
        ->assertJsonPath('data.game.id', $game->id)
        ->assertJsonPath('data.team_stats.0.stats.rushing_yards', 120)
        ->assertJsonPath('meta.contract', 'sports.games.page.show')
        ->assertJsonMissingPath('data.prediction.model_metadata');
    expect($page->json('data.prediction'))->toBe($prediction);
});

it('allows a game without a prediction but rejects missing games and unauthenticated readers', function () {
    $this->getJson('/api/v2/sports/nfl/games/1/page')->assertUnauthorized();
    config(['subscriptions.enforce_tiers' => false]);
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create();
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertSuccessful()->assertJsonPath('data.prediction', null);
    $this->getJson('/api/v2/sports/nfl/games/999999/page')->assertNotFound();
});

it('does not bypass subscription access through the composite page', function () {
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create();
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertForbidden();
});
