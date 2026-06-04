<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\CBB\TeamMetric as CbbTeamMetric;
use App\Models\CFB\Team as CfbTeam;
use App\Models\CFB\TeamMetric as CfbTeamMetric;
use App\Models\MLB\Team as MlbTeam;
use App\Models\MLB\TeamMetric as MlbTeamMetric;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NBA\TeamMetric as NbaTeamMetric;
use App\Models\NFL\Team as NflTeam;
use App\Models\NFL\TeamMetric as NflTeamMetric;
use App\Models\User;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WCBB\TeamMetric as WcbbTeamMetric;
use App\Models\WNBA\Team as WnbaTeam;
use App\Models\WNBA\TeamMetric as WnbaTeamMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2TeamMetricContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaTeamMetric::class],
    'nfl' => ['nfl', NflTeam::class, NflTeamMetric::class],
    'mlb' => ['mlb', MlbTeam::class, MlbTeamMetric::class],
    'cbb' => ['cbb', CbbTeam::class, CbbTeamMetric::class],
    'cfb' => ['cfb', CfbTeam::class, CfbTeamMetric::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbTeamMetric::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaTeamMetric::class],
]);

it('requires authenticated access for v2 team metric endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/metrics/teams")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/metrics/teams/available-seasons")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/teams/1/metrics")
        ->assertUnauthorized();
})->with('v2TeamMetricContractSports');

it('returns a clean json 404 for unsupported v2 sport team metric endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/metrics/teams')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/teams/1/metrics')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 team metrics with stable metadata and flat metric fields', function (
    string $slug,
    string $teamModel,
    string $metricModel,
) {
    actAsV2TeamMetricContractUser();

    $team = $teamModel::factory()->create([
        'abbreviation' => strtoupper($slug),
    ]);
    $metric = createV2TeamMetricContractMetric($metricModel, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 12,
        'losses' => 4,
        'net_rating' => 8.5,
        'offensive_rating' => 115.2,
        'offensive_efficiency' => 114.1,
        'calculation_date' => '2026-06-04',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/metrics/teams?season=2026&season_type=2&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'team_id',
                    'season',
                    'season_type',
                    'team',
                    'calculation_date',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'version',
                'sport',
                'contract',
                'filters',
                'pagination',
                'tier',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.metrics.teams.index')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.season_type', '2')
        ->assertJsonPath('data.0.id', $metric->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.team_id', $team->id)
        ->assertJsonPath('data.0.wins', 12)
        ->assertJsonPath('data.0.losses', 4);

    expect($response->json('data.0.team'))->toBeArray()
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2TeamMetricContractSports');

it('lists v2 team metric seasons and latest team metric with metadata', function (
    string $slug,
    string $teamModel,
    string $metricModel,
) {
    actAsV2TeamMetricContractUser();

    $team = $teamModel::factory()->create([
        'abbreviation' => strtoupper($slug),
    ]);
    createV2TeamMetricContractMetric($metricModel, [
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 9,
        'losses' => 7,
        'calculation_date' => '2025-06-04',
    ]);
    $latest = createV2TeamMetricContractMetric($metricModel, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 13,
        'losses' => 3,
        'calculation_date' => '2026-06-04',
    ]);

    $this->getJson("/api/v2/sports/{$slug}/metrics/teams/available-seasons")
        ->assertOk()
        ->assertJsonPath('data.0', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.metrics.teams.available-seasons');

    $this->getJson("/api/v2/sports/{$slug}/teams/{$team->id}/metrics")
        ->assertOk()
        ->assertJsonPath('data.id', $latest->id)
        ->assertJsonPath('data.team_id', $team->id)
        ->assertJsonPath('data.season', 2026)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.teams.metrics.show');
})->with('v2TeamMetricContractSports');

function actAsV2TeamMetricContractUser(): User
{
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $metricModel
 * @param  array<string, mixed>  $attributes
 */
function createV2TeamMetricContractMetric(string $metricModel, array $attributes): Model
{
    $table = (new $metricModel)->getTable();
    $payload = collect($attributes)
        ->filter(fn (mixed $value, string $key): bool => Schema::hasColumn($table, $key))
        ->all();

    return Model::unguarded(fn () => $metricModel::query()->create($payload));
}
