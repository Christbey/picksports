<?php

use App\Models\CBB\Team;
use App\Models\CBB\TournamentForecast;
use App\Models\CBB\TournamentStateSnapshot;
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

it('uses the latest live snapshot rows when available', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('view-cbb-predictions', 'web');
    $user->givePermissionTo('view-cbb-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create([
        'school' => 'Live',
        'mascot' => 'Team',
        'abbreviation' => 'LIV',
        'conference' => 'Test Conf',
    ]);

    $snapshot = TournamentStateSnapshot::query()->create([
        'season' => 2027,
        'as_of' => now(),
        'source' => 'manual',
        'status' => 'completed',
        'games_final_count' => 2,
        'games_remaining_count' => 10,
        'field_size' => 68,
    ]);

    TournamentForecast::factory()->create([
        'team_id' => $team->id,
        'season' => 2027,
        'snapshot_id' => $snapshot->id,
        'as_of' => now(),
        'mode' => 'live',
        'region' => 'West',
        'seed' => 4,
        'is_first_four' => false,
        'is_alive' => true,
        'is_eliminated' => false,
        'reached_round' => 'round_of_64',
        'round_of_32_probability' => 0.74,
        'sweet_16_probability' => 0.51,
        'elite_8_probability' => 0.29,
        'final_four_probability' => 0.14,
        'title_game_probability' => 0.08,
        'champion_probability' => 0.03,
        'games_final_count' => 2,
        'tournament_make_probability' => 1.0,
        'simulation_runs' => 0,
    ]);

    $response = $this->getJson('/api/v1/cbb/tournament-forecasts?season=2027');

    $response->assertOk()
        ->assertJsonPath('meta.mode', 'live_snapshot')
        ->assertJsonPath('meta.snapshot_id', $snapshot->id);

    $row = collect($response->json('data'))->firstWhere('team_id', $team->id);

    expect($row)->not->toBeNull()
        ->and($row['actual_region'])->toBe('West')
        ->and($row['actual_seed'])->toBe(4)
        ->and((float) $row['champion_probability'])->toBe(0.03)
        ->and((float) $row['round_of_32_probability'])->toBe(0.74);
});

it('returns placeholder teams from live snapshots when no team mapping exists', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('view-cbb-predictions', 'web');
    $user->givePermissionTo('view-cbb-predictions');
    Sanctum::actingAs($user);

    $snapshot = TournamentStateSnapshot::query()->create([
        'season' => 2028,
        'as_of' => now(),
        'source' => 'manual',
        'status' => 'completed',
        'games_final_count' => 0,
        'games_remaining_count' => 31,
        'field_size' => 68,
    ]);

    TournamentForecast::query()->create([
        'snapshot_id' => $snapshot->id,
        'placeholder_key' => 'fallback:2028:West:15',
        'team_id' => null,
        'season' => 2028,
        'as_of' => now(),
        'mode' => 'live',
        'region' => 'West',
        'seed' => 15,
        'team_display_name' => 'Queens Royals',
        'team_abbreviation' => 'QUE',
        'is_first_four' => false,
        'is_alive' => true,
        'is_eliminated' => false,
        'reached_round' => 'round_of_64',
        'selection_score' => 0,
        'projected_seed' => 15,
        'auto_bid' => false,
        'auto_bid_probability' => 0,
        'at_large_probability' => 0,
        'tournament_make_probability' => 1,
        'first_four_probability' => 0,
        'first_four_auto_probability' => 0,
        'first_four_at_large_probability' => 0,
        'bid_thief_probability' => 0,
        'round_of_32_probability' => 0,
        'sweet_16_probability' => 0,
        'elite_8_probability' => 0,
        'final_four_probability' => 0,
        'title_game_probability' => 0,
        'champion_probability' => 0,
        'games_final_count' => 0,
        'simulation_runs' => 0,
    ]);

    $response = $this->getJson('/api/v1/cbb/tournament-forecasts?season=2028');

    $response->assertOk()
        ->assertJsonPath('meta.mode', 'live_snapshot')
        ->assertJsonPath('meta.actual_field_size', 68);

    $row = collect($response->json('data'))->firstWhere('placeholder_key', 'fallback:2028:West:15');

    expect($row)->not->toBeNull()
        ->and($row['team_id'])->toBeNull()
        ->and($row['is_actual_field'])->toBeTrue()
        ->and($row['actual_region'])->toBe('West')
        ->and($row['actual_seed'])->toBe(15)
        ->and($row['team']['display_name'])->toBe('Queens Royals')
        ->and($row['team']['abbreviation'])->toBe('QUE');
});
