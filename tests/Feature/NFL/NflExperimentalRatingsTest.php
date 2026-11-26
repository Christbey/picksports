<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('restricts experimental custom EP ratings to administrators', function () {
    $this->get('/admin/nfl-experimental-ratings')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/admin/nfl-experimental-ratings')->assertForbidden();
});

it('shows stored custom ratings only in the explicit admin research view', function () {
    config(['nfl.predictions.true_epa.custom_epa_quarantined' => true]);
    $team = Team::factory()->create();
    $metric = TeamMetric::create([
        'team_id' => $team->id, 'season' => 2026, 'season_type' => 2,
        'offensive_true_epa_per_play' => 0.15, 'defensive_true_epa_per_play' => -0.1,
        'net_true_epa_per_play' => 0.25,
    ]);
    TeamMetric::create(['team_id' => $team->id, 'season' => 2025, 'season_type' => 2, 'net_true_epa_per_play' => 9]);
    expect($metric->fresh()->net_true_epa_per_play)->toBeNull();
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/nfl-experimental-ratings?season=2026')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/NflExperimentalRatings')
            ->where('quarantined', true)->where('season', 2026)
            ->where('validation_status', 'Not validated for pregame prediction')
            ->has('ratings', 1)->where('ratings.0.net', 0.25)
            ->where('ratings.0.custom_rating_calculated_at', null));
    expect((float) $metric->fresh()->getRawOriginal('net_true_epa_per_play'))->toBe(0.25);
});

it('rejects invalid experimental rating seasons', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/admin/nfl-experimental-ratings?season=invalid')->assertUnprocessable();
});
