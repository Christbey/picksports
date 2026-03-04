<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\ApplicationSetting;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Settings\FoundingUsersSettingsService;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config()->set('founding_users.enabled', true);
    config()->set('founding_users.limit', 2);
    config()->set('founding_users.role', 'founding_user');
    config()->set('founding_users.tier_slug', 'premium');

    Role::query()->firstOrCreate([
        'name' => 'founding_user',
        'guard_name' => 'web',
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Free',
        'slug' => 'free',
        'description' => 'Free',
        'price_monthly' => null,
        'price_yearly' => null,
        'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null,
        'features' => ['predictions_per_day' => 5],
        'permissions' => [],
        'data_permissions' => [],
        'predictions_limit' => 5,
        'team_metrics_limit' => 10,
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Premium',
        'slug' => 'premium',
        'description' => 'Premium',
        'price_monthly' => 99.99,
        'price_yearly' => 999.99,
        'stripe_price_id_monthly' => 'price_premium_monthly',
        'stripe_price_id_yearly' => 'price_premium_yearly',
        'features' => ['predictions_per_day' => null],
        'permissions' => [],
        'data_permissions' => [],
        'predictions_limit' => null,
        'team_metrics_limit' => null,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 99,
    ]);
});

it('grants founding role only to the first configured number of users', function () {
    $action = app(CreateNewUser::class);

    $first = $action->create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'age_verified' => '1',
    ]);

    $second = $action->create([
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'age_verified' => '1',
    ]);

    $third = $action->create([
        'name' => 'Third User',
        'email' => 'third@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'age_verified' => '1',
    ]);

    expect($first->fresh()->hasRole('founding_user'))->toBeTrue();
    expect($second->fresh()->hasRole('founding_user'))->toBeTrue();
    expect($third->fresh()->hasRole('founding_user'))->toBeFalse();
});

it('blocks checkout for founding users', function () {
    config()->set('subscriptions.tiers.basic.stripe_price_id.monthly', 'price_test_basic_monthly');

    SubscriptionTier::query()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'description' => 'Basic',
        'price_monthly' => 9.99,
        'price_yearly' => 99.99,
        'stripe_price_id_monthly' => 'price_test_basic_monthly',
        'stripe_price_id_yearly' => 'price_test_basic_yearly',
        'features' => ['predictions_per_day' => 25],
        'permissions' => [],
        'data_permissions' => [],
        'predictions_limit' => 25,
        'team_metrics_limit' => 25,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $user = User::factory()->create();
    $user->assignRole('founding_user');

    actingAs($user)
        ->post('/subscription/checkout', [
            'tier' => 'basic',
            'billing_period' => 'monthly',
        ])
        ->assertSessionHas('error');
});

it('uses database founding limit override for signup assignment', function () {
    ApplicationSetting::setValue(FoundingUsersSettingsService::LIMIT_KEY, 1);

    $action = app(CreateNewUser::class);

    $first = $action->create([
        'name' => 'First DB Limit User',
        'email' => 'first-db-limit@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'age_verified' => '1',
    ]);

    $second = $action->create([
        'name' => 'Second DB Limit User',
        'email' => 'second-db-limit@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'age_verified' => '1',
    ]);

    expect($first->fresh()->hasRole('founding_user'))->toBeTrue();
    expect($second->fresh()->hasRole('founding_user'))->toBeFalse();
});
