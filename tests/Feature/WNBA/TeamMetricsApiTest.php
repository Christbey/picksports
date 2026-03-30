<?php

use App\Models\User;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use Laravel\Sanctum\Sanctum;

uses()->group('wnba', 'team-metrics', 'api');

it('returns the shared pro basketball team metrics contract for wnba', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $team = Team::factory()->create();

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'offensive_efficiency' => 103.4,
        'defensive_efficiency' => 99.2,
        'net_rating' => 4.2,
        'tempo' => 78.6,
        'strength_of_schedule' => 1512.345,
        'recent_form_rating' => 2.125,
        'injury_adjusted_team_rating' => 1504.3,
        'rest_travel_fatigue' => 1.75,
    ]);

    $this->getJson('/api/v1/wnba/team-metrics?season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.offensive_efficiency', '103.4')
        ->assertJsonPath('data.0.defensive_efficiency', '99.2')
        ->assertJsonPath('data.0.offensive_rating', '103.4')
        ->assertJsonPath('data.0.defensive_rating', '99.2')
        ->assertJsonPath('data.0.tempo', '78.6')
        ->assertJsonPath('data.0.pace', '78.6')
        ->assertJsonPath('data.0.strength_of_schedule', '1512.345');
});
