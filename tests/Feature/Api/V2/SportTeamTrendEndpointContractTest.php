<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

dataset('v2TeamTrendContractSports', [
    'nba' => ['nba', 'NBA', NbaTeam::class],
    'nfl' => ['nfl', 'NFL', NflTeam::class],
    'mlb' => ['mlb', 'MLB', MlbTeam::class],
    'cbb' => ['cbb', 'CBB', CbbTeam::class],
    'cfb' => ['cfb', 'CFB', CfbTeam::class],
    'wcbb' => ['wcbb', 'WCBB', WcbbTeam::class],
    'wnba' => ['wnba', 'WNBA', WnbaTeam::class],
]);

it('requires authenticated access for v2 team trend endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/teams/1/trends")
        ->assertUnauthorized();
})->with('v2TeamTrendContractSports');

it('returns a clean json 404 for unsupported v2 team trend endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/teams/1/trends')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('shows v2 team trends with stable metadata and scored signals', function (
    string $slug,
    string $namespace,
    string $teamModel,
) {
    Cache::flush();
    Sanctum::actingAs(User::factory()->create());

    /** @var Model $team */
    $team = $teamModel::factory()->create(['abbreviation' => 'TST']);
    $calculatorClass = "App\\Actions\\{$namespace}\\CalculateTeamTrends";

    $this->mock($calculatorClass)
        ->shouldReceive('countAvailableGames')
        ->once()
        ->with(Mockery::on(fn (Model $arg): bool => $arg->getKey() === $team->getKey()), 2026, '2', '2026-06-01')
        ->andReturn(12)
        ->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (Model $arg): bool => $arg->getKey() === $team->getKey()),
            12,
            2026,
            '2',
            '2026-06-01',
            Mockery::type('string'),
        )
        ->andReturn([
            'trends' => [
                'scoring' => ['Covered in 9 of their last 12 games.'],
            ],
            'locked' => [
                'advanced' => 'premium',
            ],
        ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/teams/{$team->getKey()}/trends?games=season&season=2026&season_type=2&before_date=2026-06-01")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'team_id',
                'team_abbreviation',
                'team_name',
                'sample_size',
                'user_tier',
                'trends',
                'scored_signals',
                'trend_signal_summary',
                'locked_trends',
            ],
            'meta' => [
                'version',
                'sport',
                'contract',
                'team_id',
                'filters',
                'tier',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.teams.trends.show')
        ->assertJsonPath('meta.team_id', $team->getKey())
        ->assertJsonPath('meta.filters.games', 'season')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('data.team_id', $team->getKey())
        ->assertJsonPath('data.team_abbreviation', 'TST')
        ->assertJsonPath('data.sample_size', 12)
        ->assertJsonPath('data.trends.scoring.0', 'Covered in 9 of their last 12 games.')
        ->assertJsonPath('data.locked_trends.advanced', 'premium');

    expect($response->json('data.scored_signals'))->toBeArray()
        ->and($response->json('data.trend_signal_summary'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2TeamTrendContractSports');
