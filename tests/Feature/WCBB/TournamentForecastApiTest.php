<?php

use App\Models\User;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TournamentForecast;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('wcbb', 'api');

beforeEach(function () {
    Permission::findOrCreate('view-wcbb-predictions', 'web');

    config()->set('wcbb.season.default', 2026);
    config()->set('wcbb.tournament_forecast.field_size', 4);
    config()->set('wcbb.tournament_forecast.auto_bids', 2);
    config()->set('wcbb.tournament_forecast.simulations', 150);
    config()->set('wcbb.tournament_forecast.random_seed', 12345);
    config()->set('wcbb.tournament_forecast.refresh.enabled', true);
    config()->set('wcbb.tournament_forecast.refresh.minimum_coverage_ratio', 0.95);
    config()->set('wcbb.tournament_forecast.refresh.stale_after_hours', 6);
});

it('returns wcbb tournament forecasts for authenticated users', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-wcbb-predictions');
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
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/wcbb/tournament-forecasts?season=2026');

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

it('regenerates stale or partial wcbb forecasts before responding', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-wcbb-predictions');
    Sanctum::actingAs($user);

    $teams = collect([
        ['school' => 'Alpha', 'mascot' => 'Aces', 'abbreviation' => 'ALP', 'conference' => 'Alpha Conf', 'elo' => 1620, 'adj' => 30.0, 'rolling' => 28.0, 'sos' => 1515.0],
        ['school' => 'Bravo', 'mascot' => 'Bears', 'abbreviation' => 'BRV', 'conference' => 'Alpha Conf', 'elo' => 1580, 'adj' => 24.0, 'rolling' => 22.0, 'sos' => 1508.0],
        ['school' => 'Charlie', 'mascot' => 'Cats', 'abbreviation' => 'CHR', 'conference' => 'Beta Conf', 'elo' => 1600, 'adj' => 27.0, 'rolling' => 26.0, 'sos' => 1512.0],
        ['school' => 'Delta', 'mascot' => 'Dogs', 'abbreviation' => 'DLT', 'conference' => 'Beta Conf', 'elo' => 1560, 'adj' => 20.0, 'rolling' => 19.0, 'sos' => 1504.0],
        ['school' => 'Echo', 'mascot' => 'Eagles', 'abbreviation' => 'ECH', 'conference' => 'Gamma Conf', 'elo' => 1540, 'adj' => 18.0, 'rolling' => 17.0, 'sos' => 1498.0],
        ['school' => 'Foxtrot', 'mascot' => 'Foxes', 'abbreviation' => 'FOX', 'conference' => 'Gamma Conf', 'elo' => 1500, 'adj' => 12.0, 'rolling' => 11.0, 'sos' => 1492.0],
    ])->map(function (array $seedData) {
        $team = Team::factory()->create([
            'school' => $seedData['school'],
            'mascot' => $seedData['mascot'],
            'abbreviation' => $seedData['abbreviation'],
            'conference' => $seedData['conference'],
            'elo_rating' => $seedData['elo'],
        ]);

        TeamMetric::create([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 105.0,
            'defensive_efficiency' => 95.0,
            'net_rating' => $seedData['adj'],
            'tempo' => 69.0,
            'strength_of_schedule' => $seedData['sos'],
            'games_played' => 20,
            'meets_minimum' => true,
            'adj_offensive_efficiency' => 106.0,
            'adj_defensive_efficiency' => 94.0,
            'adj_net_rating' => $seedData['adj'],
            'rolling_offensive_efficiency' => 104.0,
            'rolling_defensive_efficiency' => 96.0,
            'rolling_net_rating' => $seedData['rolling'],
            'rolling_tempo' => 69.0,
            'rolling_games_count' => 10,
            'calculation_date' => now()->toDateString(),
        ]);

        return $team;
    });

    for ($i = 0; $i < $teams->count(); $i += 2) {
        Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $teams[$i]->id,
            'away_team_id' => $teams[$i + 1]->id,
            'status' => 'STATUS_FINAL',
            'home_score' => 78 - $i,
            'away_score' => 62 - $i,
        ]);
    }

    TournamentForecast::factory()->create([
        'team_id' => $teams[0]->id,
        'season' => 2026,
        'updated_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/v1/wcbb/tournament-forecasts?season=2026');

    $response->assertOk();

    $oldestUpdatedAt = TournamentForecast::query()->where('season', 2026)->min('updated_at');

    expect(TournamentForecast::query()->where('season', 2026)->count())->toBe(6)
        ->and(count($response->json('data')))->toBe(6)
        ->and($oldestUpdatedAt)->not->toBeNull()
        ->and(now()->diffInHours(Carbon::parse($oldestUpdatedAt)))->toBeLessThan(1);
});
