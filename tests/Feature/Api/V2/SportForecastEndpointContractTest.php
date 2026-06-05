<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\CBB\TournamentForecast as CbbTournamentForecast;
use App\Models\MLB\PlayoffForecast as MlbPlayoffForecast;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\PlayoffForecast as NbaPlayoffForecast;
use App\Models\NBA\Team as NbaTeam;
use App\Models\User;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WCBB\TournamentForecast as WcbbTournamentForecast;
use App\Services\NFL\TeamPlayoffForecastService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

it('requires authenticated access for v2 forecast endpoints', function () {
    $this->getJson('/api/v2/sports/nba/forecasts')
        ->assertUnauthorized();
});

it('returns clean json 404 responses for unsupported v2 forecast endpoints', function () {
    actAsV2ForecastContractUser();

    $this->getJson('/api/v2/sports/nhl/forecasts')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/wnba/forecasts')
        ->assertNotFound()
        ->assertJsonPath('message', 'Forecasts are not available for wnba.');
});

it('lists nba playoff forecasts with v2 metadata and v1-compatible rows', function () {
    Cache::flush();
    actAsV2ForecastContractUser();

    $team = NbaTeam::factory()->create(['abbreviation' => 'TST']);
    NbaPlayoffForecast::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'conference' => 'Eastern',
        'conference_rank' => 2,
        'projected_seed' => 2,
        'selection_score' => 1.25,
        'playoff_make_probability' => 0.82,
        'direct_playoff_probability' => 0.72,
        'play_in_tournament_probability' => 0.10,
        'division_win_probability' => 0.40,
        'conference_finals_probability' => 0.22,
        'nba_finals_probability' => 0.12,
        'champion_probability' => 0.08,
        'simulation_runs' => 5000,
    ]);

    $this->getJson('/api/v2/sports/nba/forecasts?season=2026')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'nba')
        ->assertJsonPath('meta.contract', 'sports.forecasts.index')
        ->assertJsonPath('meta.season', 2026)
        ->assertJsonPath('meta.requested_season', 2026)
        ->assertJsonPath('meta.playoff_teams_per_conference', 8)
        ->assertJsonPath('data.0.team.abbreviation', 'TST')
        ->assertJsonPath('data.0.playoff_make_probability', 0.82)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'team',
                    'market_odds',
                    'market_edge',
                ],
            ],
            'meta' => [
                'available_seasons',
                'freshness',
                'warnings',
            ],
        ]);
});

it('lists mlb playoff forecasts with v2 metadata and v1-compatible rows', function () {
    Cache::flush();
    actAsV2ForecastContractUser();

    $team = MlbTeam::factory()->create(['abbreviation' => 'SEA']);
    MlbPlayoffForecast::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'league' => 'American',
        'league_rank' => 3,
        'projected_seed' => 3,
        'selection_score' => 1.10,
        'playoff_make_probability' => 0.74,
        'league_championship_probability' => 0.18,
        'world_series_probability' => 0.10,
        'champion_probability' => 0.05,
        'simulation_runs' => 5000,
    ]);

    $this->getJson('/api/v2/sports/mlb/forecasts?season=2026')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'mlb')
        ->assertJsonPath('meta.season', 2026)
        ->assertJsonPath('data.0.team.abbreviation', 'SEA')
        ->assertJsonPath('data.0.playoff_make_probability', 0.74)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'market_odds',
                    'market_edge',
                ],
            ],
        ]);
});

it('lists cbb tournament forecasts with live field metadata', function () {
    Cache::flush();
    actAsV2ForecastContractUser();

    $team = CbbTeam::factory()->create(['abbreviation' => 'KU']);
    createV2ForecastModel(CbbTournamentForecast::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'mode' => 'baseline',
        'selection_score' => 2.1,
        'projected_seed' => 1,
        'auto_bid' => true,
        'auto_bid_probability' => 0.85,
        'at_large_probability' => 0.10,
        'tournament_make_probability' => 0.95,
        'champion_probability' => 0.12,
        'final_four_probability' => 0.36,
        'title_game_probability' => 0.20,
        'simulation_runs' => 5000,
    ]);

    $this->getJson('/api/v2/sports/cbb/forecasts?season=2026')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'cbb')
        ->assertJsonPath('meta.mode', 'baseline')
        ->assertJsonPath('meta.actual_field_size', 0)
        ->assertJsonPath('data.0.team.abbreviation', 'KU')
        ->assertJsonPath('data.0.tournament_make_probability', 0.95)
        ->assertJsonPath('data.0.is_actual_field', false)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'actual_round',
                    'actual_region',
                    'actual_seed',
                    'market_odds',
                    'market_edge',
                ],
            ],
        ]);
});

it('lists wcbb tournament forecasts with v2 metadata and v1-compatible rows', function () {
    Cache::flush();
    actAsV2ForecastContractUser();

    $team = WcbbTeam::factory()->create(['abbreviation' => 'SC']);
    createV2ForecastModel(WcbbTournamentForecast::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'selection_score' => 2.3,
        'projected_seed' => 1,
        'auto_bid' => true,
        'auto_bid_probability' => 0.90,
        'at_large_probability' => 0.05,
        'tournament_make_probability' => 0.97,
        'champion_probability' => 0.18,
        'final_four_probability' => 0.44,
        'title_game_probability' => 0.26,
        'simulation_runs' => 5000,
    ]);

    $this->getJson('/api/v2/sports/wcbb/forecasts?season=2026')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'wcbb')
        ->assertJsonPath('meta.season', 2026)
        ->assertJsonPath('data.0.team.abbreviation', 'SC')
        ->assertJsonPath('data.0.tournament_make_probability', 0.97)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'market_odds',
                    'market_edge',
                ],
            ],
        ]);
});

it('lists nfl playoff forecasts from the canonical forecast service', function () {
    Cache::flush();
    actAsV2ForecastContractUser();

    $this->mock(TeamPlayoffForecastService::class)
        ->shouldReceive('forecast')
        ->once()
        ->with(2026, '2026-09-01', true)
        ->andReturn([
            'summary' => ['simulations' => 5000],
            'teams' => [
                [
                    'team_id' => 1,
                    'team_name' => 'Kansas City Chiefs',
                    'conference' => 'AFC',
                    'division' => 'West',
                    'projected_wins' => 11.4,
                    'projected_seed' => 1.7,
                    'division_winner_probability' => 0.64,
                    'make_playoffs_probability' => 0.83,
                    'conference_champion_probability' => 0.24,
                    'super_bowl_champion_probability' => 0.14,
                ],
            ],
        ]);

    $this->getJson('/api/v2/sports/nfl/forecasts?season=2026&as_of_date=2026-09-01&require_historical_metrics=1')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'nfl')
        ->assertJsonPath('meta.simulations', 5000)
        ->assertJsonPath('data.0.team_name', 'Kansas City Chiefs')
        ->assertJsonPath('data.0.super_bowl_champion_probability', 0.14)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'market_odds',
                    'market_edge',
                ],
            ],
        ]);
});

function actAsV2ForecastContractUser(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $modelClass
 * @param  array<string, mixed>  $attributes
 */
function createV2ForecastModel(string $modelClass, array $attributes): Model
{
    $columns = Schema::getColumnListing((new $modelClass)->getTable());
    $now = now();
    $payload = array_merge([
        'created_at' => $now,
        'updated_at' => $now,
    ], $attributes);

    return $modelClass::query()->create(array_intersect_key(
        $payload,
        array_flip($columns),
    ));
}
