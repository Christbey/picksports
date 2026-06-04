<?php

use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Player as WcbbPlayer;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

dataset('v2PlayerContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaPlayer::class],
    'nfl' => ['nfl', NflTeam::class, NflPlayer::class],
    'mlb' => ['mlb', MlbTeam::class, MlbPlayer::class],
    'cbb' => ['cbb', CbbTeam::class, CbbPlayer::class],
    'cfb' => ['cfb', CfbTeam::class, CfbPlayer::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbPlayer::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaPlayer::class],
]);

it('requires authenticated access for v2 player endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/players")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/players/1")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/teams/1/players")
        ->assertUnauthorized();
})->with('v2PlayerContractSports');

it('returns a clean json 404 for unsupported v2 sport player endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/players')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/players/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/teams/1/players')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 players with sport, filter, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $playerModel,
) {
    actAsV2PlayerContractUser();

    $team = $teamModel::factory()->create();
    $player = createV2PlayerContractPlayer($playerModel, [
        'team_id' => $team->id,
        'full_name' => 'Contract Player',
        'first_name' => 'Contract',
        'last_name' => 'Player',
        'position' => 'G',
        'status' => 'Active',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/players?search=Contract&position=G&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'team_id',
                    'espn_id',
                    'first_name',
                    'last_name',
                    'full_name',
                    'display_name',
                    'jersey_number',
                    'position',
                    'height',
                    'weight',
                    'headshot_url',
                    'team',
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
        ->assertJsonPath('meta.filters.position', 'G')
        ->assertJsonPath('data.0.id', $player->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.team_id', $team->id);

    expect($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PlayerContractSports');

it('shows a v2 player with sport, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $playerModel,
) {
    actAsV2PlayerContractUser();

    $team = $teamModel::factory()->create();
    $player = createV2PlayerContractPlayer($playerModel, [
        'team_id' => $team->id,
        'full_name' => 'Shown Player',
        'first_name' => 'Shown',
        'last_name' => 'Player',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/players/{$player->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'sport',
                'team_id',
                'espn_id',
                'first_name',
                'last_name',
                'full_name',
                'display_name',
                'jersey_number',
                'position',
                'team',
            ],
            'meta' => [
                'sport',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('data.id', $player->id)
        ->assertJsonPath('data.sport', $slug)
        ->assertJsonPath('data.team_id', $team->id);

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PlayerContractSports');

it('lists v2 players for a team with sport, team, filter, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $playerModel,
) {
    actAsV2PlayerContractUser();

    $team = $teamModel::factory()->create();
    $otherTeam = $teamModel::factory()->create();
    $player = createV2PlayerContractPlayer($playerModel, [
        'team_id' => $team->id,
        'full_name' => 'Team Contract Player',
        'first_name' => 'Team',
        'last_name' => 'Player',
        'position' => 'G',
    ]);
    createV2PlayerContractPlayer($playerModel, [
        'team_id' => $otherTeam->id,
        'full_name' => 'Other Contract Player',
        'first_name' => 'Other',
        'last_name' => 'Player',
        'position' => 'G',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/teams/{$team->id}/players?search=Contract&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'team_id',
                    'full_name',
                    'position',
                    'team',
                ],
            ],
            'meta' => [
                'sport',
                'team_id',
                'filters',
                'pagination',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.team_id', $team->id)
        ->assertJsonPath('meta.filters.search', 'Contract')
        ->assertJsonPath('data.0.id', $player->id)
        ->assertJsonPath('data.0.team_id', $team->id);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2PlayerContractSports');

function actAsV2PlayerContractUser(): User
{
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  class-string<Model>  $playerModel
 * @param  array<string, mixed>  $overrides
 */
function createV2PlayerContractPlayer(string $playerModel, array $overrides = []): Model
{
    $table = (new $playerModel)->getTable();
    $attributes = [
        'team_id' => $overrides['team_id'] ?? null,
        'espn_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'first_name' => $overrides['first_name'] ?? 'Contract',
        'last_name' => $overrides['last_name'] ?? 'Player',
        'full_name' => $overrides['full_name'] ?? trim(($overrides['first_name'] ?? 'Contract').' '.($overrides['last_name'] ?? 'Player')),
        'name' => $overrides['full_name'] ?? 'Contract Player',
        'display_name' => $overrides['full_name'] ?? 'Contract Player',
        'jersey_number' => 12,
        'position' => $overrides['position'] ?? 'G',
        'height' => '6-5',
        'weight' => 205,
        'age' => 28,
        'experience' => 5,
        'year' => 'Senior',
        'college' => 'Contract University',
        'hometown' => 'Contract City, TX',
        'status' => $overrides['status'] ?? 'Active',
        'batting_hand' => 'R',
        'throwing_hand' => 'R',
        'headshot_url' => 'https://example.com/player.png',
    ];

    $attributes = array_merge($attributes, $overrides);

    return $playerModel::query()->create(array_filter(
        $attributes,
        fn (mixed $value, string $column): bool => $value !== null && Schema::hasColumn($table, $column),
        ARRAY_FILTER_USE_BOTH,
    ));
}
