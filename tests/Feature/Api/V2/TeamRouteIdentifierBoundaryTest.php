<?php

use App\Models\User;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerQuery;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    Sanctum::actingAs(User::factory()->admin()->create());
    $this->routeFixtures = [];
    foreach (['nba', 'nfl'] as $sport) {
        $namespace = 'App\\Models\\'.strtoupper($sport).'\\';
        $team = $namespace.'Team';
        $game = $namespace.'Game';
        $player = $namespace.'Player';
        [$a, $b, $c] = [$team::factory()->create(), $team::factory()->create(), $team::factory()->create()];
        $wanted = $game::factory()->create(['home_team_id' => $a->id, 'away_team_id' => $b->id,
            'name' => $sport.' scoped game', 'season' => 2026, 'season_type' => 2,
            'game_date' => '2026-11-20', 'game_time' => '18:00:00', 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
        $game::factory()->create(['home_team_id' => $b->id, 'away_team_id' => $c->id,
            'name' => $sport.' unrelated game', 'season' => 2026, 'season_type' => 2,
            'game_date' => '2026-11-21', 'game_time' => '18:00:00', 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
        $wantedPlayer = $player::factory()->create(['team_id' => $a->id, 'full_name' => $sport.' Scoped Player']);
        $player::factory()->create(['team_id' => $b->id, 'full_name' => $sport.' Unrelated Player']);
        $this->routeFixtures[$sport] = ['team' => $a->id, 'game' => $wanted->id, 'player' => $wantedPlayer->id];
    }
});

it('refuses malformed path team identifiers instead of coercing or broadening a list', function (string $sport, string $endpoint, string $kind) {
    $id = match ($kind) {
        'partial' => $this->routeFixtures[$sport]['team'].'junk',
        'zero' => '0',
        'overflow' => str_repeat('9', 40),
        default => 'garbage',
    };
    $this->getJson('/api/v2/sports/'.$sport.'/teams/'.$id.'/'.$endpoint)->assertNotFound();
    Http::assertNothingSent();
})->with([
    ['nba', 'games', 'garbage'], ['nba', 'games', 'partial'], ['nba', 'games', 'zero'], ['nba', 'games', 'overflow'],
    ['nba', 'players', 'garbage'], ['nba', 'players', 'partial'], ['nba', 'players', 'zero'], ['nba', 'players', 'overflow'],
    ['nfl', 'games', 'garbage'], ['nfl', 'games', 'partial'], ['nfl', 'games', 'zero'], ['nfl', 'games', 'overflow'],
    ['nfl', 'players', 'garbage'], ['nfl', 'players', 'partial'], ['nfl', 'players', 'zero'], ['nfl', 'players', 'overflow'],
]);

it('retains valid scoped IDs and the requested sport despite colliding local IDs', function (string $sport, string $endpoint) {
    expect($this->routeFixtures['nba']['team'])->toBe($this->routeFixtures['nfl']['team']);
    $fixture = $this->routeFixtures[$sport];
    $response = $this->getJson('/api/v2/sports/'.$sport.'/teams/'.$fixture['team'].'/'.$endpoint)
        ->assertOk()->assertJsonPath('meta.sport', $sport)->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $fixture[$endpoint === 'games' ? 'game' : 'player']);
    $response->assertJsonPath($endpoint === 'games' ? 'data.0.name' : 'data.0.full_name',
        $sport.($endpoint === 'games' ? ' scoped game' : ' Scoped Player'));
})->with([['nba', 'games'], ['nba', 'players'], ['nfl', 'games'], ['nfl', 'players']]);

it('preserves zero padded positive IDs without broadening unscoped lists', function (string $sport, string $endpoint) {
    $fixture = $this->routeFixtures[$sport];
    $this->getJson('/api/v2/sports/'.$sport.'/teams/000'.$fixture['team'].'/'.$endpoint)
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixture[$endpoint === 'games' ? 'game' : 'player']);
    $this->getJson('/api/v2/sports/'.$sport.'/'.$endpoint)->assertOk()->assertJsonCount(2, 'data');
})->with([['nba', 'games'], ['nba', 'players'], ['nfl', 'games'], ['nfl', 'players']]);

it('keeps direct team zero and unknown positive queries scoped instead of returning other teams', function (string $sport) {
    $context = app(SportContextResolver::class)->resolve($sport);
    $players = app(SportPlayerQuery::class);
    expect($players->paginateForTeam($context, 0)->total())->toBe(0)
        ->and($players->paginateForTeam($context, 999999)->total())->toBe(0)
        ->and($players->paginateForTeam($context, $this->routeFixtures[$sport]['team'])->total())->toBe(1)
        ->and($players->paginate($context)->total())->toBe(2);
})->with(['nba', 'nfl']);
