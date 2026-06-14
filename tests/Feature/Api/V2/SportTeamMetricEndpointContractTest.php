<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\CBB\TeamMetric as CbbTeamMetric;
use App\Models\CFB\Team as CfbTeam;
use App\Models\CFB\TeamMetric as CfbTeamMetric;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\MLB\TeamMetric as MlbTeamMetric;
use App\Models\MLB\TeamStat as MlbTeamStat;
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
        ->assertJsonPath('data.0.losses', 4)
        ->assertJsonPath('data.0.games_played', 16)
        ->assertJsonPath('data.0.record_label', '12-4')
        ->assertJsonPath('data.0.record.source', 'metric');

    expect($response->json('data.0.team'))->toBeArray()
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2TeamMetricContractSports');

it('aliases basketball efficiency fields to game page metric names', function () {
    actAsV2TeamMetricContractUser();

    $team = NbaTeam::factory()->create();
    createV2TeamMetricContractMetric(NbaTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => '2',
        'offensive_efficiency' => 116.4,
        'defensive_efficiency' => 109.2,
        'net_rating' => 7.2,
        'tempo' => 99.8,
    ]);

    $this->getJson("/api/v2/sports/nba/teams/{$team->id}/metrics?season=2026")
        ->assertOk()
        ->assertJsonPath('data.offensive_rating', 116.4)
        ->assertJsonPath('data.defensive_rating', 109.2)
        ->assertJsonPath('data.pace', 99.8);
});

it('defaults mlb team metrics to regular season rows', function () {
    actAsV2TeamMetricContractUser();

    $team = MlbTeam::factory()->create([
        'abbreviation' => 'KC',
    ]);

    createV2TeamMetricContractMetric(MlbTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'wins' => 4,
        'losses' => 3,
        'offensive_rating' => 225.0,
        'calculation_date' => '2026-03-20',
    ]);

    $regularMetric = createV2TeamMetricContractMetric(MlbTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 28,
        'losses' => 40,
        'offensive_rating' => 132.4,
        'calculation_date' => '2026-06-12',
    ]);

    $this->getJson('/api/v2/sports/mlb/metrics/teams?season=2026&per_page=5')
        ->assertOk()
        ->assertJsonPath('meta.filters.season_type', (string) config('mlb.season.types.regular', 2))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $regularMetric->id)
        ->assertJsonPath('data.0.wins', 28)
        ->assertJsonPath('data.0.losses', 40)
        ->assertJsonPath('data.0.record_label', '28-40');

    $this->getJson('/api/v2/sports/mlb/metrics/teams?season=2026&season_type=all&per_page=5')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('defaults wnba team metrics to regular season rows', function () {
    actAsV2TeamMetricContractUser();

    $team = WnbaTeam::factory()->create([
        'abbreviation' => 'LV',
    ]);

    createV2TeamMetricContractMetric(WnbaTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('wnba.season.types.postseason', 3),
        'wins' => 2,
        'losses' => 0,
        'offensive_efficiency' => 111.0,
        'calculation_date' => '2026-09-20',
    ]);

    $regularMetric = createV2TeamMetricContractMetric(WnbaTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('wnba.season.types.regular', 2),
        'wins' => 18,
        'losses' => 8,
        'offensive_efficiency' => 104.5,
        'calculation_date' => '2026-06-14',
    ]);

    $this->getJson('/api/v2/sports/wnba/metrics/teams?season=2026&per_page=5')
        ->assertOk()
        ->assertJsonPath('meta.filters.season_type', (string) config('wnba.season.types.regular', 2))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $regularMetric->id)
        ->assertJsonPath('data.0.wins', 18)
        ->assertJsonPath('data.0.losses', 8)
        ->assertJsonPath('data.0.record_label', '18-8');

    $this->getJson('/api/v2/sports/wnba/metrics/teams?season=2026&season_type=all&per_page=5')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('exposes every mlb team metrics table column used by the frontend', function () {
    actAsV2TeamMetricContractUser();

    $team = MlbTeam::factory()->create([
        'abbreviation' => 'LAD',
        'location' => 'Los Angeles',
        'name' => 'Dodgers',
    ]);

    createV2TeamMetricContractMetric(MlbTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 43,
        'losses' => 27,
        'runs_per_game' => 5.43,
        'runs_allowed_per_game' => 3.31,
        'run_differential_per_game' => 2.12,
        'batting_average' => 0.264,
        'on_base_percentage' => 0.343,
        'slugging_percentage' => 0.443,
        'ops' => 0.786,
        'home_runs_per_game' => 1.36,
        'team_era' => 3.29,
        'strikeouts_pitched_per_game' => 8.94,
        'whip' => 1.09,
        'offensive_rating' => 148.1,
        'pitching_rating' => 73.3,
        'strength_of_schedule' => 1483.142,
        'recent_form_rating' => 1.25,
        'injury_adjusted_team_rating' => 1512.456,
        'rest_travel_fatigue' => 0.75,
        'calculation_date' => '2026-06-12',
    ]);

    $response = $this->getJson('/api/v2/sports/mlb/metrics/teams?season=2026&season_type=2&per_page=5')
        ->assertOk()
        ->assertJsonPath('data.0.team.abbreviation', 'LAD')
        ->assertJsonPath('data.0.record_label', '43-27')
        ->assertJsonPath('data.0.games_played', 70)
        ->assertJsonPath('data.0.runs_per_game', 5.43)
        ->assertJsonPath('data.0.runs_allowed_per_game', 3.31)
        ->assertJsonPath('data.0.run_differential_per_game', 2.12)
        ->assertJsonPath('data.0.batting_average', 0.264)
        ->assertJsonPath('data.0.on_base_percentage', 0.343)
        ->assertJsonPath('data.0.slugging_percentage', 0.443)
        ->assertJsonPath('data.0.ops', 0.786)
        ->assertJsonPath('data.0.home_runs_per_game', 1.36)
        ->assertJsonPath('data.0.team_era', 3.29)
        ->assertJsonPath('data.0.strikeouts_pitched_per_game', 8.94)
        ->assertJsonPath('data.0.whip', 1.09)
        ->assertJsonPath('data.0.offensive_rating', 148.1)
        ->assertJsonPath('data.0.pitching_rating', 73.3)
        ->assertJsonPath('data.0.strength_of_schedule', 1483.142)
        ->assertJsonPath('data.0.recent_form_rating', 1.25)
        ->assertJsonPath('data.0.injury_adjusted_team_rating', 1512.456)
        ->assertJsonPath('data.0.rest_travel_fatigue', 0.75);

    foreach ([
        'wins',
        'losses',
        'runs_per_game',
        'runs_allowed_per_game',
        'run_differential_per_game',
        'batting_average',
        'on_base_percentage',
        'slugging_percentage',
        'ops',
        'home_runs_per_game',
        'team_era',
        'strikeouts_pitched_per_game',
        'whip',
        'offensive_rating',
        'pitching_rating',
        'strength_of_schedule',
        'recent_form_rating',
        'injury_adjusted_team_rating',
        'rest_travel_fatigue',
    ] as $field) {
        $value = $response->json("data.0.{$field}");

        expect(is_int($value) || is_float($value))->toBeTrue("Expected {$field} to be numeric.");
    }
});

it('derives mlb team metric records from completed games when stored rows are stale zeros', function () {
    actAsV2TeamMetricContractUser();

    $team = MlbTeam::factory()->create([
        'abbreviation' => 'KC',
    ]);
    $opponent = MlbTeam::factory()->create([
        'abbreviation' => 'STL',
    ]);

    $win = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-06-01',
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 0,
        'away_score' => 0,
    ]);
    MlbTeamStat::factory()->create([
        'team_id' => $team->id,
        'game_id' => $win->id,
        'team_type' => 'home',
        'runs' => 5,
    ]);
    MlbTeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $win->id,
        'team_type' => 'away',
        'runs' => 3,
    ]);

    $loss = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-06-02',
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $opponent->id,
        'away_team_id' => $team->id,
        'home_score' => 0,
        'away_score' => 0,
    ]);
    MlbTeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $loss->id,
        'team_type' => 'home',
        'runs' => 6,
    ]);
    MlbTeamStat::factory()->create([
        'team_id' => $team->id,
        'game_id' => $loss->id,
        'team_type' => 'away',
        'runs' => 2,
    ]);

    createV2TeamMetricContractMetric(MlbTeamMetric::class, [
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 0,
        'losses' => 0,
        'offensive_rating' => 132.4,
        'calculation_date' => '2026-06-12',
    ]);

    $response = $this->getJson('/api/v2/sports/mlb/metrics/teams?season=2026&per_page=5')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('team_id', $team->id);

    expect($row)->not->toBeNull()
        ->and($row['wins'])->toBe(1)
        ->and($row['losses'])->toBe(1)
        ->and($row['games_played'])->toBe(2)
        ->and($row['record_label'])->toBe('1-1')
        ->and($row['record']['source'])->toBe('derived_games');
});

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
