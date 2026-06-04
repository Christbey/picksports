<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;
use App\Models\Sports\FuturesOdd;
use App\Models\User;
use App\Models\WCBB\Team as WcbbTeam;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;

dataset('v2FuturesContractSports', [
    'nba' => ['nba', NbaTeam::class, 'nba_team_id'],
    'nfl' => ['nfl', NflTeam::class, 'nfl_team_id'],
    'mlb' => ['mlb', MlbTeam::class, 'mlb_team_id'],
    'cbb' => ['cbb', CbbTeam::class, 'cbb_team_id'],
    'wcbb' => ['wcbb', WcbbTeam::class, 'wcbb_team_id'],
]);

it('requires authenticated access for v2 futures endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/markets/futures")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/teams/1/futures")
        ->assertUnauthorized();
})->with('v2FuturesContractSports');

it('returns a clean json 404 for unsupported v2 futures endpoints', function () {
    actAsV2FuturesContractUser();

    $this->getJson('/api/v2/sports/nhl/markets/futures')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/teams/1/futures')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('lists v2 futures odds with stable shape, filters, pagination, freshness, and warnings', function (
    string $slug,
    string $teamModel,
    string $teamForeignKey,
) {
    actAsV2FuturesContractUser();

    $team = $teamModel::factory()->create();
    $odd = createV2FuturesContractOdd($slug, $teamForeignKey, $team, [
        'season' => 2026,
        'market_key' => 'championship_winner',
        'bookmaker' => 'draftkings',
        'outcome_name' => 'Contract Winner',
        'price' => 450,
    ]);
    createV2FuturesContractOdd($slug, $teamForeignKey, $team, [
        'season' => 2026,
        'market_key' => 'season_wins',
        'bookmaker' => 'draftkings',
        'outcome_name' => 'Over',
        'price' => -110,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/markets/futures?season=2026&market_key=championship_winner&bookmaker=draftkings&team_id={$team->id}&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport',
                    'season',
                    'odds_api_sport_key',
                    'event_id',
                    'event_name',
                    'bookmaker',
                    'market_key',
                    'outcome' => [
                        'name',
                        'description',
                        'point',
                        'price',
                        'implied_probability',
                    ],
                    'entity',
                    'fetched_at',
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
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.market_key', 'championship_winner')
        ->assertJsonPath('meta.filters.bookmaker', 'draftkings')
        ->assertJsonPath('meta.filters.team_id', $team->id)
        ->assertJsonPath('data.0.id', $odd->id)
        ->assertJsonPath('data.0.sport', $slug)
        ->assertJsonPath('data.0.market_key', 'championship_winner')
        ->assertJsonPath('data.0.outcome.price', 450)
        ->assertJsonPath('data.0.entity.id', $team->id);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();

    assertV2FuturesContractHasNoRawLeakage($response->json());
})->with('v2FuturesContractSports');

it('lists v2 team-scoped futures odds with forced team filter', function (
    string $slug,
    string $teamModel,
    string $teamForeignKey,
) {
    actAsV2FuturesContractUser();

    $team = $teamModel::factory()->create();
    $otherTeam = $teamModel::factory()->create();
    $odd = createV2FuturesContractOdd($slug, $teamForeignKey, $team, [
        'season' => 2026,
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_point' => 91.5,
        'price' => -115,
    ]);
    createV2FuturesContractOdd($slug, $teamForeignKey, $otherTeam, [
        'season' => 2026,
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_point' => 82.5,
        'price' => -105,
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/teams/{$team->id}/futures?season=2026&market_key=season_wins")
        ->assertOk()
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.team_id', $team->id)
        ->assertJsonPath('meta.filters.team_id', $team->id)
        ->assertJsonPath('meta.filters.market_key', 'season_wins')
        ->assertJsonPath('data.0.id', $odd->id)
        ->assertJsonPath('data.0.entity.id', $team->id)
        ->assertJsonPath('data.0.outcome.point', 91.5);

    expect($response->json('data'))->toHaveCount(1);

    assertV2FuturesContractHasNoRawLeakage($response->json());
})->with('v2FuturesContractSports');

function actAsV2FuturesContractUser(): User
{
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    return $user;
}

function createV2FuturesContractOdd(string $sport, string $teamForeignKey, Model $team, array $overrides = []): FuturesOdd
{
    return FuturesOdd::query()->create(array_merge([
        'row_key' => $sport.'-contract-'.fake()->unique()->uuid(),
        'sport' => $sport,
        'season' => 2026,
        'odds_api_sport_key' => "{$sport}_futures",
        'event_id' => "{$sport}-championship",
        'event_name' => strtoupper($sport).' Championship',
        $teamForeignKey => $team->id,
        'bookmaker' => 'draftkings',
        'market_key' => 'championship_winner',
        'market_last_update' => '2026-06-04 12:00:00',
        'outcome_name' => 'Contract Winner',
        'outcome_description' => $team->display_name ?? $team->name ?? $team->school ?? 'Contract Team',
        'outcome_point' => null,
        'price' => 450,
        'implied_probability' => 0.181818,
        'raw_data' => ['source' => 'contract-test', 'must_not' => 'leak'],
        'fetched_at' => '2026-06-04 12:05:00',
    ], $overrides));
}

function assertV2FuturesContractHasNoRawLeakage(array $payload): void
{
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    foreach (['raw_data', 'contract-test', 'must_not', 'leak'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
}
