<?php

use App\Actions\CBB\GradeBrackets;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CbbBracket;
use App\Models\Group;
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
        'name' => 'Alpha Entry',
        'picks' => [],
        'points_earned' => 12,
        'correct_picks' => 5,
    ]);

    CbbBracket::query()->create([
        'user_id' => $second->id,
        'season' => 2026,
        'name' => 'Beta Entry',
        'picks' => [],
        'points_earned' => 8,
        'correct_picks' => 4,
    ]);

    $this->actingAs($first)
        ->getJson('/api/v1/cbb-brackets/leaderboard?season=2026')
        ->assertOk()
        ->assertJsonPath('data.0.bracket_name', 'Alpha Entry')
        ->assertJsonPath('data.0.rank', 1)
        ->assertJsonPath('data.0.user_name', 'Alpha')
        ->assertJsonPath('data.1.bracket_name', 'Beta Entry')
        ->assertJsonPath('data.1.user_name', 'Beta')
        ->assertJsonPath('data.1.rank', 2);
});

test('bracket leaderboard can be filtered by group', function () {
    $owner = User::factory()->create(['name' => 'Owner']);
    $member = User::factory()->create(['name' => 'Member']);
    $outside = User::factory()->create(['name' => 'Outside']);

    $group = Group::query()->create([
        'owner_id' => $owner->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);

    $group->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
    $group->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    CbbBracket::query()->create([
        'user_id' => $owner->id,
        'group_id' => $group->id,
        'season' => 2026,
        'name' => 'Owner Entry',
        'picks' => [],
        'points_earned' => 20,
        'correct_picks' => 6,
    ]);

    CbbBracket::query()->create([
        'user_id' => $member->id,
        'group_id' => $group->id,
        'season' => 2026,
        'name' => 'Member Entry',
        'picks' => [],
        'points_earned' => 15,
        'correct_picks' => 5,
    ]);

    CbbBracket::query()->create([
        'user_id' => $outside->id,
        'season' => 2026,
        'picks' => [],
        'points_earned' => 99,
        'correct_picks' => 10,
    ]);

    $this->actingAs($owner)
        ->getJson("/api/v1/cbb-brackets/leaderboard?season=2026&group_id={$group->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.bracket_name', 'Owner Entry')
        ->assertJsonPath('data.1.bracket_name', 'Member Entry');
});
