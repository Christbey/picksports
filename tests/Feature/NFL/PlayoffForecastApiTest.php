<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('nfl', 'playoff-forecasts');

it('returns nfl playoff forecasts through the api route', function () {
    Permission::findOrCreate('view-nfl-predictions', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('view-nfl-predictions');
    Sanctum::actingAs($user);

    $teams = [
        ['id' => 1, 'location' => 'Buffalo', 'name' => 'Bills', 'abbreviation' => 'BUF', 'conference' => 'AFC', 'division' => 'East', 'predictive_rating' => 9.0, 'recent_form_rating' => 5.0],
        ['id' => 2, 'location' => 'Miami', 'name' => 'Dolphins', 'abbreviation' => 'MIA', 'conference' => 'AFC', 'division' => 'East', 'predictive_rating' => 5.0, 'recent_form_rating' => 2.0],
        ['id' => 3, 'location' => 'Philadelphia', 'name' => 'Eagles', 'abbreviation' => 'PHI', 'conference' => 'NFC', 'division' => 'East', 'predictive_rating' => 8.0, 'recent_form_rating' => 4.0],
        ['id' => 4, 'location' => 'Dallas', 'name' => 'Cowboys', 'abbreviation' => 'DAL', 'conference' => 'NFC', 'division' => 'East', 'predictive_rating' => 4.0, 'recent_form_rating' => 1.0],
    ];

    foreach ($teams as $team) {
        Team::factory()->create([
            'id' => $team['id'],
            'location' => $team['location'],
            'name' => $team['name'],
            'abbreviation' => $team['abbreviation'],
            'conference' => $team['conference'],
            'division' => $team['division'],
        ]);

        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('nfl-playoff-'.$team['abbreviation']),
            'team_id' => $team['id'],
            'season' => 2026,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => $team['predictive_rating'],
            'future_strength_of_schedule' => 1500.000,
            'recent_form_rating' => $team['recent_form_rating'],
            'injury_total_adjustment' => 0.000,
            'calculation_date' => '2026-04-03',
            'captured_at' => '2026-04-03 18:00:00',
        ]);
    }

    $response = $this->getJson('/api/v1/nfl/playoff-forecasts?season=2026&as_of_date=2026-04-03T18:00:00Z&require_historical_metrics=1');

    $response->assertOk();
    $response->assertJsonPath('meta.season', 2026);
    $response->assertJsonPath('meta.require_historical_metrics', true);
    $response->assertJsonCount(4, 'data');
    $response->assertJsonPath('data.0.team_name', 'Buffalo Bills');
    $response->assertJsonStructure([
        'data' => [[
            'team_id',
            'team_name',
            'conference',
            'division',
            'projected_wins',
            'projected_seed',
            'division_winner_probability',
            'make_playoffs_probability',
            'conference_champion_probability',
            'super_bowl_champion_probability',
            'market_edge',
        ]],
        'meta' => [
            'season',
            'as_of_date',
            'require_historical_metrics',
            'sort_by',
            'sort_direction',
            'simulations',
        ],
    ]);
});
