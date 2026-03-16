<?php

use App\Actions\CBB\GradeBrackets;
use App\Models\CbbBracket;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\User;

test('grade brackets stores correct and incorrect results for finalized tournament games', function () {
    $user = User::factory()->create();

    $duke = Team::factory()->create(['school' => 'Duke', 'mascot' => 'Blue Devils', 'abbreviation' => 'DUKE']);
    $siena = Team::factory()->create(['school' => 'Siena', 'mascot' => 'Saints', 'abbreviation' => 'SIE']);
    $louisville = Team::factory()->create(['school' => 'Louisville', 'mascot' => 'Cardinals', 'abbreviation' => 'LOU']);
    $usf = Team::factory()->create(['school' => 'South Florida', 'mascot' => 'Bulls', 'abbreviation' => 'USF']);

    $dukeGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_team_id' => $duke->id,
        'away_team_id' => $siena->id,
        'home_seed' => 1,
        'away_seed' => 16,
        'status' => config('cbb.statuses.final'),
        'home_score' => 81,
        'away_score' => 54,
        'name' => 'Siena Saints at Duke Blue Devils',
    ]);

    $louisvilleGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_team_id' => $louisville->id,
        'away_team_id' => $usf->id,
        'home_seed' => 6,
        'away_seed' => 11,
        'status' => config('cbb.statuses.final'),
        'home_score' => 70,
        'away_score' => 73,
        'name' => 'South Florida Bulls at Louisville Cardinals',
    ]);

    $bracket = CbbBracket::query()->create([
        'user_id' => $user->id,
        'season' => 2026,
        'picks' => [
            "game:{$dukeGame->id}" => "team:{$duke->id}",
            "game:{$louisvilleGame->id}" => "team:{$louisville->id}",
        ],
    ]);

    app(GradeBrackets::class)->execute(2026, $bracket->fresh());

    $bracket->refresh();

    expect($bracket->points_earned)->toBe(10)
        ->and($bracket->correct_picks)->toBe(1)
        ->and($bracket->incorrect_picks)->toBe(1)
        ->and($bracket->results["game:{$dukeGame->id}"]['status'])->toBe('correct')
        ->and($bracket->results["game:{$dukeGame->id}"]['points'])->toBe(10)
        ->and($bracket->results["game:{$dukeGame->id}"]['possible_points'])->toBe(10)
        ->and($bracket->results["game:{$louisvilleGame->id}"]['status'])->toBe('incorrect')
        ->and($bracket->results["game:{$louisvilleGame->id}"]['points'])->toBe(0)
        ->and($bracket->results["game:{$louisvilleGame->id}"]['possible_points'])->toBe(10);
});

test('bracket leaderboard returns ranked bracket rows', function () {
    $first = User::factory()->create(['name' => 'Alpha']);
    $second = User::factory()->create(['name' => 'Beta']);

    CbbBracket::query()->create([
        'user_id' => $first->id,
        'season' => 2026,
        'picks' => [],
        'points_earned' => 12,
        'correct_picks' => 5,
    ]);

    CbbBracket::query()->create([
        'user_id' => $second->id,
        'season' => 2026,
        'picks' => [],
        'points_earned' => 8,
        'correct_picks' => 4,
    ]);

    $this->actingAs($first)
        ->getJson('/api/v1/cbb-brackets/leaderboard?season=2026')
        ->assertOk()
        ->assertJsonPath('data.0.user_name', 'Alpha')
        ->assertJsonPath('data.0.rank', 1)
        ->assertJsonPath('data.1.user_name', 'Beta')
        ->assertJsonPath('data.1.rank', 2);
});
