<?php

use App\Models\CBB\Team;
use App\Models\CBB\TournamentForecast;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('cbb', 'api');

it('returns cbb tournament forecasts for authenticated users', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('view-cbb-predictions', 'web');
    $user->givePermissionTo('view-cbb-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create([
        'school' => 'Test U',
        'mascot' => 'Falcons',
        'abbreviation' => 'TST',
        'conference' => 'Test Conf',
    ]);

    TournamentForecast::factory()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'champion_probability' => 0.052,
        'tournament_make_probability' => 0.88,
        'auto_bid_probability' => 0.31,
        'at_large_probability' => 0.57,
        'first_four_probability' => 0.11,
        'first_four_auto_probability' => 0.03,
        'first_four_at_large_probability' => 0.08,
        'bid_thief_probability' => 0.07,
    ]);

    $response = $this->getJson('/api/v1/cbb/tournament-forecasts?season=2026');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'team_id',
                'season',
                'projected_seed',
                'auto_bid',
                'auto_bid_probability',
                'at_large_probability',
                'tournament_make_probability',
                'first_four_probability',
                'first_four_auto_probability',
                'first_four_at_large_probability',
                'bid_thief_probability',
                'champion_probability',
                'final_four_probability',
                'title_game_probability',
                'team',
            ]],
            'meta' => [
                'season',
                'available_seasons',
            ],
        ]);

    expect($response->json('data.0.team.school'))->toBe('Test U')
        ->and((float) $response->json('data.0.champion_probability'))->toBe(0.052);
});
