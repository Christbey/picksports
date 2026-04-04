<?php

use App\Models\MLB\BullpenRating;
use App\Models\MLB\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('mlb', 'api');

beforeEach(function () {
    Permission::findOrCreate('view-mlb-predictions', 'web');
});

it('returns ranked mlb bullpen ratings for a requested snapshot date', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-mlb-predictions');
    Sanctum::actingAs($user);

    $topTeam = Team::factory()->create(['abbreviation' => 'TOP']);
    $bottomTeam = Team::factory()->create(['abbreviation' => 'BOT']);

    BullpenRating::query()->create([
        'team_id' => $topTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'as_of_date' => '2026-04-03',
        'games_sampled' => 8,
        'weighted_usage' => 0.71,
        'weighted_era' => 3.02,
        'weighted_whip' => 1.08,
        'strikeouts_per_nine' => 10.2,
        'walks_per_nine' => 2.5,
        'home_runs_per_nine' => 0.8,
        'recent_form_score' => 0.9,
        'workload_penalty' => 0.4,
        'rating_score' => 113.5,
        'rating_rank' => 1,
        'calculation_date' => now()->toDateString(),
    ]);

    BullpenRating::query()->create([
        'team_id' => $bottomTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'as_of_date' => '2026-04-03',
        'games_sampled' => 8,
        'weighted_usage' => 0.69,
        'weighted_era' => 4.88,
        'weighted_whip' => 1.44,
        'strikeouts_per_nine' => 7.1,
        'walks_per_nine' => 4.3,
        'home_runs_per_nine' => 1.6,
        'recent_form_score' => -0.7,
        'workload_penalty' => 0.8,
        'rating_score' => 82.1,
        'rating_rank' => 2,
        'calculation_date' => now()->toDateString(),
    ]);

    $response = $this->getJson('/api/v1/mlb/bullpen-ratings?season=2026&season_type=2&as_of_date=2026-04-03');

    $response->assertOk()
        ->assertJsonPath('data.0.team_id', $topTeam->id)
        ->assertJsonPath('data.0.rating_rank', 1)
        ->assertJsonPath('data.0.rating_score', 113.5)
        ->assertJsonPath('data.1.team_id', $bottomTeam->id)
        ->assertJsonPath('data.1.rating_rank', 2);
});
