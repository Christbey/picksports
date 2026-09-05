<?php

use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\DepthChartEntry as NflDepthChartEntry;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Player as WcbbPlayer;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2InjuryContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaPlayer::class],
    'nfl' => ['nfl', NflTeam::class, NflPlayer::class],
    'mlb' => ['mlb', MlbTeam::class, MlbPlayer::class],
    'cbb' => ['cbb', CbbTeam::class, CbbPlayer::class],
    'cfb' => ['cfb', CfbTeam::class, CfbPlayer::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbPlayer::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaPlayer::class],
]);

it('requires authenticated access for v2 injury endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/injuries")
        ->assertUnauthorized();
})->with('v2InjuryContractSports');

it('returns only actionable nfl injuries with depth and impact context', function () {
    actAsV2InjuryContractUser();
    $team = NflTeam::factory()->create(['abbreviation' => 'ACT']);
    $player = NflPlayer::factory()->create([
        'team_id' => $team->id,
        'full_name' => 'Actionable Quarterback',
        'position' => 'QB',
    ]);
    NflDepthChartEntry::query()->create([
        'team_id' => $team->id,
        'player_id' => $player->id,
        'season' => now()->year,
        'position_slot_key' => 'QB',
        'position_code' => 'QB',
        'depth_rank' => 1,
        'is_starter' => true,
        'source_updated_at' => now(),
    ]);
    createV2InjuryContractRow('nfl', $team, $player, [
        'injury_key' => 'actionable-out',
        'status' => 'Out',
        'return_date' => now()->addWeek()->toDateString(),
    ]);
    createV2InjuryContractRow('nfl', $team, $player, [
        'injury_key' => 'resolved-active',
        'status' => 'Active',
        'type' => 'INJURY_STATUS_ACTIVE',
    ]);
    createV2InjuryContractRow('nfl', $team, $player, [
        'injury_key' => 'expired-questionable',
        'status' => 'Questionable',
        'return_date' => now()->subDay()->toDateString(),
    ]);

    $this->getJson('/api/v2/sports/nfl/injuries?active=1&actionable=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'Out')
        ->assertJsonPath('data.0.position', 'QB')
        ->assertJsonPath('data.0.is_starter', true)
        ->assertJsonPath('data.0.availability_probability', 0)
        ->assertJsonPath('data.0.impact_level', 'critical')
        ->assertJsonPath('meta.freshness.is_stale', false);
});

it('returns a clean json 404 for unsupported v2 injury endpoints', function () {
    actAsV2InjuryContractUser();

    $this->getJson('/api/v2/sports/nhl/injuries')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 injuries with stable metadata and filters', function (
    string $slug,
    string $teamModel,
    string $playerModel,
) {
    actAsV2InjuryContractUser();

    $team = $teamModel::factory()->create(['abbreviation' => 'TST']);
    $player = createV2InjuryContractPlayer($playerModel, [
        'team_id' => $team->id,
        'full_name' => 'Contract Injury Player',
        'first_name' => 'Contract',
        'last_name' => 'Player',
    ]);

    createV2InjuryContractRow($slug, $team, $player, [
        'status' => 'Out',
        'is_active' => true,
    ]);
    createV2InjuryContractRow($slug, $team, $player, [
        'injury_key' => 'inactive-contract-injury',
        'status' => 'Questionable',
        'is_active' => false,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/injuries?active=1&team_id={$team->id}&status=Out")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'player_id',
                    'team_id',
                    'status',
                    'detail',
                    'type',
                    'injury_date',
                    'return_date',
                    'source_updated_at',
                    'is_active',
                    'updated_at',
                    'team_abbreviation',
                    'player_name',
                ],
            ],
            'meta' => [
                'version',
                'sport',
                'contract',
                'filters',
                'total',
                'teams',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.injuries.index')
        ->assertJsonPath('meta.filters.active', true)
        ->assertJsonPath('meta.filters.team_id', $team->id)
        ->assertJsonPath('meta.filters.status', 'Out')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.teams', 1)
        ->assertJsonPath('data.0.status', 'Out')
        ->assertJsonPath('data.0.team_abbreviation', 'TST');

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2InjuryContractSports');

function actAsV2InjuryContractUser(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $playerModel
 * @param  array<string, mixed>  $attributes
 */
function createV2InjuryContractPlayer(string $playerModel, array $attributes): Model
{
    $columns = Schema::getColumnListing((new $playerModel)->getTable());

    if (in_array('espn_id', $columns, true) && ! array_key_exists('espn_id', $attributes)) {
        $attributes['espn_id'] = 'contract-injury-player-'.strtolower(class_basename($playerModel));
    }

    return $playerModel::factory()->create(array_intersect_key(
        $attributes,
        array_flip($columns),
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createV2InjuryContractRow(string $slug, Model $team, Model $player, array $overrides = []): void
{
    $table = "{$slug}_player_injuries";
    $columns = Schema::getColumnListing($table);
    $now = now();
    $payload = [
        'player_id' => $player->getKey(),
        'team_id' => $team->getKey(),
        'injury_key' => 'active-contract-injury',
        'espn_injury_id' => 'contract-injury',
        'status' => 'Out',
        'detail' => 'Contract test injury',
        'type' => 'Lower Body',
        'injury_date' => $now->toDateString(),
        'return_date' => $now->copy()->addWeek()->toDateString(),
        'source_updated_at' => $now,
        'is_active' => true,
        'raw_payload' => json_encode(['contract' => true]),
        'created_at' => $now,
        'updated_at' => $now,
    ];

    DB::table($table)->insert(array_intersect_key(
        array_merge($payload, $overrides),
        array_flip($columns),
    ));
}
