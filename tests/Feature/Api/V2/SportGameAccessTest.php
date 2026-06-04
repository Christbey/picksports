<?php

use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Support\SubscriptionTierCache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(SubscriptionTierCache::class)->bust();

    config()->set('subscriptions.enforce_tiers', true);
});

function createV2ApiAccessTier(string $slug, array $features = [], array $permissions = []): SubscriptionTier
{
    return SubscriptionTier::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'description' => "{$slug} test tier",
        'features' => array_merge([
            'api_access' => false,
            'sports_access' => [],
        ], $features),
        'permissions' => $permissions,
        'data_permissions' => [],
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

function actAsV2TierUser(SubscriptionTier $tier): User
{
    $user = User::factory()->create();

    Role::findOrCreate($tier->slug, 'web');
    $user->assignRole($tier->slug);

    Sanctum::actingAs($user);

    return $user;
}

it('keeps v2 sport metadata public while sport game endpoints require sanctum auth', function () {
    $this->getJson('/api/v2/sports')
        ->assertOk();

    $this->getJson('/api/v2/sports/mlb')
        ->assertOk();

    $this->getJson('/api/v2/sports/mlb/games')
        ->assertUnauthorized();
});

it('denies v2 sport games when the user lacks product API access', function () {
    $tier = createV2ApiAccessTier('v2-no-api', [
        'api_access' => false,
        'sports_access' => ['MLB'],
    ]);

    actAsV2TierUser($tier);

    $this->getJson('/api/v2/sports/mlb/games')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your subscription does not include V2 API access.');
});

it('denies v2 sport games when the user lacks access to the requested sport', function () {
    $tier = createV2ApiAccessTier('v2-nba-only', [
        'api_access' => true,
        'sports_access' => ['NBA'],
    ]);

    actAsV2TierUser($tier);

    $this->getJson('/api/v2/sports/mlb/games')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your subscription does not include mlb API access.');
});

it('allows v2 sport games when the user has product API and sport access', function () {
    $tier = createV2ApiAccessTier('v2-mlb-api', [
        'api_access' => true,
        'sports_access' => ['MLB'],
    ]);

    actAsV2TierUser($tier);

    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();
    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
    ]);

    $this->getJson('/api/v2/sports/mlb/games?season=2026')
        ->assertOk()
        ->assertJsonPath('data.0.id', $game->id)
        ->assertJsonPath('meta.sport', 'mlb');
});
