<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2TeamContractSports', [
    'nba' => ['nba', NbaTeam::class],
    'nfl' => ['nfl', NflTeam::class],
    'mlb' => ['mlb', MlbTeam::class],
    'cbb' => ['cbb', CbbTeam::class],
    'cfb' => ['cfb', CfbTeam::class],
    'wcbb' => ['wcbb', WcbbTeam::class],
    'wnba' => ['wnba', WnbaTeam::class],
]);

it('requires authenticated access for v2 team endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/teams")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/teams/1")
        ->assertUnauthorized();
})->with('v2TeamContractSports');

it('returns a clean json 404 for unsupported v2 sport team endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/teams')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/teams/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 teams with sport, filter, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
) {
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    $team = $teamModel::factory()->create(array_filter([
        'abbreviation' => strtoupper($slug),
        teamContractNameColumn($teamModel) => 'Contract Team',
    ]));

    $response = $this->getJson("/api/v2/sports/{$slug}/teams?search=Contract&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'espn_id',
                    'abbreviation',
                    'location',
                    'name',
                    'display_name',
                    'conference',
                    'division',
                    'logo_url',
                ],
            ],
            'meta' => [
                'sport',
                'filters',
                'pagination',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.filters.search', 'Contract')
        ->assertJsonPath('data.0.id', $team->id)
        ->assertJsonPath('data.0.sport', $slug);

    expect($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2TeamContractSports');

function teamContractNameColumn(string $teamModel): string
{
    $table = (new $teamModel)->getTable();

    return Schema::hasColumn($table, 'name') ? 'name' : 'school';
}

it('shows a v2 team with sport, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
) {
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    $team = $teamModel::factory()->create();

    $response = $this->getJson("/api/v2/sports/{$slug}/teams/{$team->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'sport',
                'espn_id',
                'abbreviation',
                'location',
                'name',
                'display_name',
                'conference',
                'division',
                'logo_url',
            ],
            'meta' => [
                'sport',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.sport', $slug);

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2TeamContractSports');
