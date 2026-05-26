<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SubscriptionTier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function seedTrendTiers(): void
{
    SubscriptionTier::query()->create([
        'name' => 'Free',
        'slug' => 'free',
        'description' => 'Default tier',
        'features' => ['predictions_per_day' => 5],
        'permissions' => [],
        'data_permissions' => ['spread'],
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'description' => 'Basic tier',
        'features' => ['predictions_per_day' => 25],
        'permissions' => [],
        'data_permissions' => ['spread', 'win_probability'],
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'description' => 'Pro tier',
        'features' => ['predictions_per_day' => 100],
        'permissions' => [],
        'data_permissions' => ['spread', 'win_probability', 'trends'],
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Premium',
        'slug' => 'premium',
        'description' => 'Premium tier',
        'features' => ['predictions_per_day' => 250],
        'permissions' => [],
        'data_permissions' => ['spread', 'win_probability', 'trends'],
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 3,
    ]);
}

it('uses default tier slug for trends when authenticated user has no tier role', function () {
    seedTrendTiers();
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertOk()
        ->assertJsonPath('user_tier', 'free');
});

it('uses synced tier role as effective trends tier for authenticated users', function () {
    seedTrendTiers();
    Role::findOrCreate('basic', 'web');
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('basic');
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertOk()
        ->assertJsonPath('user_tier', 'basic');
});

it('returns all trend categories without pro or premium locks', function () {
    seedTrendTiers();
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create(['abbreviation' => 'CLE']);
    $opponent = Team::factory()->create(['abbreviation' => 'NY']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    for ($i = 1; $i <= 6; $i++) {
        Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => '3',
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i),
            'home_score' => 112,
            'away_score' => 101,
            'home_linescores' => [
                ['period' => 1, 'value' => 28],
                ['period' => 2, 'value' => 27],
                ['period' => 3, 'value' => 30],
                ['period' => 4, 'value' => 27],
            ],
            'away_linescores' => [
                ['period' => 1, 'value' => 25],
                ['period' => 2, 'value' => 24],
                ['period' => 3, 'value' => 26],
                ['period' => 4, 'value' => 26],
            ],
        ]);
    }

    $response = $this->getJson(
        "/api/v1/nba/teams/{$team->id}/trends?games=season&season=2026&season_type=3"
    );

    $response->assertOk()
        ->assertJsonPath('user_tier', 'free')
        ->assertJsonPath('locked_trends', []);

    expect($response->json('trends.quarters'))->not->toBeEmpty()
        ->and($response->json('scored_signals'))->not->toBeEmpty()
        ->and($response->json('trend_signal_summary.counts.contextual'))->toBeGreaterThan(0)
        ->and($response->json('locked_trends.advanced'))->toBeNull()
        ->and($response->json('locked_trends.momentum'))->toBeNull();
});

it('labels spread and totals trends as model-based', function () {
    seedTrendTiers();
    Role::findOrCreate('premium', 'web');
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create(['abbreviation' => 'CLE']);
    $opponent = Team::factory()->create(['abbreviation' => 'NY']);
    $user = User::factory()->create();
    $user->assignRole('premium');
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    for ($i = 1; $i <= 6; $i++) {
        $game = Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => '3',
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i),
            'home_score' => 112,
            'away_score' => 101,
        ]);

        Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => -5.0,
            'predicted_total' => 205.0,
        ]);
    }

    $response = $this->getJson(
        "/api/v1/nba/teams/{$team->id}/trends?games=season&season=2026&season_type=3"
    );

    $response->assertOk();

    $advanced = implode(' ', $response->json('trends.advanced') ?? []);
    $totals = implode(' ', $response->json('trends.totals') ?? []);

    expect($advanced)->toContain('against the model spread')
        ->and($advanced)->not->toContain('against the spread')
        ->and($totals)->toContain('OVER the model total')
        ->and($totals)->not->toContain('with totals');
});
