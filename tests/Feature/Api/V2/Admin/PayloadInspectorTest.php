<?php

use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

it('requires authenticated admin access for the v2 admin payload inspector', function () {
    $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard')
        ->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard')
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized access.');
});

it('returns the dashboard payload inspector shell contract for admins', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $response = $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&date=2026-06-03&sports=mlb,nba')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'profile',
                'generated_at',
                'inputs' => [
                    'profile',
                    'date',
                    'sports',
                    'include_payload',
                    'include_warnings',
                ],
                'diagnostics' => [
                    'requested_sports_count',
                    'resolved_sports_count',
                    'payload_included',
                    'warnings_included',
                    'warning_count',
                ],
            ],
            'meta' => [
                'version',
                'contract',
                'profile',
                'shell',
            ],
        ])
        ->assertJsonPath('data.profile', 'dashboard')
        ->assertJsonPath('data.inputs.date', '2026-06-03')
        ->assertJsonPath('data.inputs.sports', ['mlb', 'nba'])
        ->assertJsonPath('data.inputs.include_payload', false)
        ->assertJsonPath('data.inputs.include_warnings', false)
        ->assertJsonPath('data.diagnostics.payload_included', false)
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.contract', 'admin.payload-inspector')
        ->assertJsonPath('meta.profile', 'dashboard')
        ->assertJsonPath('meta.shell', true);

    expect($response->json('data'))->not->toHaveKey('payload')
        ->and($response->json('data.diagnostics'))->not->toHaveKey('warnings');
});

it('can include persisted dashboard payload diagnostics for selected sports', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-06-03 18:10:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $response = $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&date=2026-06-03&sports=mlb&include_payload=true')
        ->assertOk()
        ->assertJsonPath('data.inputs.include_payload', true)
        ->assertJsonPath('data.payload.sports.0.slug', 'mlb')
        ->assertJsonPath('data.payload.sports.0.games.available', true)
        ->assertJsonPath('data.payload.sports.0.games.for_date', 1)
        ->assertJsonPath('data.payload.sports.0.games.total', 1)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.profile', 'dashboard')
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.vue_contract.sport_fields', ['name', 'fullName', 'color', 'predictions'])
        ->assertJsonPath('data.payload.sports.0.v2_contracts.games.available', true)
        ->assertJsonPath('data.payload.sports.0.v2_contracts.games.route', '/api/v2/sports/mlb/games')
        ->assertJsonPath('data.payload.sports.0.v2_contracts.predictions.available', true)
        ->assertJsonPath('data.payload.sports.0.v2_contracts.futures.route', '/api/v2/sports/mlb/markets/futures');

    expect($response->json('data.payload.sports.0.capabilities'))->toBeArray()
        ->and($response->json('data.payload.sports.0.games.latest_updated_at'))->not->toBeNull();
});

it('reports dashboard contract status as passing when games and predictions exist for the selected date', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-06-03 18:10:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    createPayloadInspectorMlbPrediction($game->id);

    $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&date=2026-06-03&sports=mlb&include_payload=true')
        ->assertOk()
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 1)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.status', 'passing')
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.source_counts.games_for_date', 1)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.source_counts.predictions_for_date', 1)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.warnings', []);
});

it('reports dashboard prediction gaps when games exist without matching predictions', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-06-03 18:10:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&date=2026-06-03&sports=mlb&include_payload=true')
        ->assertOk()
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 0)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.status', 'warning')
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.warnings.0.code', 'dashboard_prediction_gap');
});

it('can include warning diagnostics when requested', function () {
    config()->set('sports.domains.testsport', [
        'namespace' => '',
    ]);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&sports=testsport&include_warnings=true')
        ->assertOk()
        ->assertJsonPath('data.inputs.include_warnings', true)
        ->assertJsonPath('data.diagnostics.warning_count', 1)
        ->assertJsonPath('data.diagnostics.warnings.0.code', 'sport_unresolved');
});

it('validates the supported payload inspector profile and filters', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v2/admin/payload-inspector')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['profile']);

    $this->getJson('/api/v2/admin/payload-inspector?profile=summary&date=06-03-2026&sports=nhl')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['profile', 'date', 'sports.0']);
});

function createPayloadInspectorMlbPrediction(int $gameId): MlbPrediction
{
    $table = (new MlbPrediction)->getTable();
    $columns = array_flip(Schema::getColumnListing($table));

    return MlbPrediction::query()->create(array_intersect_key([
        'game_id' => $gameId,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => -1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.58,
        'confidence_score' => 0.62,
    ], $columns));
}
