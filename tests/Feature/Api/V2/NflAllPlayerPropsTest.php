<?php

use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['sports.business_timezone' => 'America/Chicago']);
    Sanctum::actingAs(User::factory()->create());
});

it('paginates all quotes including unscored stale and one-sided offers with stable ordering', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-10-25', 'game_time' => '17:00:00']);
    $ids = [];
    foreach (['draftkings', 'fanduel', 'betmgm'] as $book) {
        $ids[] = PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Unscored Receiver',
            'market' => 'player_receptions', 'bookmaker' => $book, 'line' => 0,
            'over_price' => 150, 'under_price' => null, 'fetched_at' => now()->subDays(3)])->id;
    }
    $url = '/api/v2/sports/nfl/player-props?date=2026-10-25&search=Unscored&per_page=1';
    $this->getJson($url)->assertOk()->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.last_page', 3)->assertJsonPath('data.0.id', $ids[2])
        ->assertJsonPath('data.0.recommendation.side', null)->assertJsonPath('data.0.line', 0)
        ->assertJsonPath('data.0.under_price', null);
    $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[1]);
    $this->getJson($url.'&page=3')->assertOk()->assertJsonPath('data.0.id', $ids[0]);
    $this->getJson($url.'&market=player_reception_yds')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'&search=Absent')->assertOk()->assertJsonCount(0, 'data');
});

it('uses the Central kickoff date for the all-props date filter and validates player search', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-10-26', 'game_time' => '00:15:00']);
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Night Receiver',
        'market' => 'player_reception_yds', 'bookmaker' => 'draftkings', 'line' => 50.5,
        'over_price' => -110, 'under_price' => -110, 'fetched_at' => now()]);
    $this->getJson('/api/v2/sports/nfl/player-props?date=2026-10-25')->assertOk()->assertJsonPath('data.0.id', $prop->id);
    $this->getJson('/api/v2/sports/nfl/player-props?date=2026-10-26')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v2/sports/nfl/player-props?search='.str_repeat('x', 101))->assertUnprocessable()->assertJsonValidationErrors('search');
});

it('retains the all-props view and existing filters in shareable page links', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $this->withoutVite();
    $this->actingAs(User::factory()->create())->get('/nfl/player-props?date=2026-10-25&view=all&market=player_receptions')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('NFL/PlayerProps')
        ->where('filters.view', 'all')->where('filters.date', '2026-10-25')->where('filters.market', 'player_receptions'));
});
