<?php

use App\Models\BillingMutationIntent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use App\Services\Billing\BillingFinancialAdmission;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true,
        'billing.account.platform_id' => 'acct_new_account_synthetic', 'billing.account.livemode' => false,
        'billing.account.api_origin' => 'https://api.stripe.com', 'app.url' => 'https://picksports.test',
        'founding_users.enabled' => false]);
    SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true,
        'permissions' => [], 'features' => [], 'data_permissions' => []]);
    SubscriptionTier::factory()->create(['slug' => 'paid_synthetic', 'name' => 'Paid synthetic',
        'stripe_price_id_monthly' => 'price_new_account_synthetic', 'permissions' => [], 'features' => [], 'data_permissions' => []]);
    Notification::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('allows actual new registration to reach paid admission with explicit default provenance and no paid rights', function () {
    $this->post('/register', ['name' => 'Synthetic new account', 'email' => 'new-default@example.test',
        'password' => 'password', 'password_confirmation' => 'password', 'age_verified' => '1'])->assertRedirect();
    $user = User::query()->where('email', 'new-default@example.test')->sole();
    expect($user->hasRole('free'))->toBeTrue();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_new_account_synthetic');
    expect($intent->kind)->toBe('checkout')->and($intent->subject_user_id)->toBe($user->id)
        ->and(DB::table('billing_role_entitlements')->sole()->origin)->toBe('system_default')
        ->and($user->subscriptions()->count())->toBe(0)
        ->and($user->hasRole('paid_synthetic'))->toBeFalse();
    Http::assertNothingSent();
});

it('captures default provenance only for an actually new OAuth account', function () {
    config(['services.oauth.providers.google.enabled' => true]);
    $socialite = (new SocialiteUser)->map(['id' => 'synthetic-default-oauth', 'name' => 'Synthetic OAuth', 'email' => 'new-oauth-default@example.test'])
        ->setRaw(['email_verified' => true])->setToken('synthetic-oauth-secret')->setRefreshToken('synthetic-oauth-refresh')->setExpiresIn(3600);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($socialite);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    $this->get('/auth/google/callback')->assertRedirect();
    $user = User::query()->where('email', 'new-oauth-default@example.test')->sole();
    expect(DB::table('billing_role_entitlements')->where('user_id', $user->id)->sole()->origin)->toBe('system_default')
        ->and($user->hasRole('free'))->toBeTrue()
        ->and(DB::table('billing_role_entitlement_events')->where('user_id', $user->id)->sole()->actor_origin)->toBe('oauth_system');
    Http::assertNothingSent();
});

it('does not infer provenance for old attached default roles before a new purchase', function () {
    $user = User::factory()->create();
    $role = app(TierPermissionSyncService::class)->syncTierRolePermissions(SubscriptionTier::query()->where('slug', 'free')->sole());
    $user->assignRole($role);
    expect(fn () => app(BillingFinancialAdmission::class)->admit($user->fresh(), 'checkout', 'price_new_account_synthetic'))->toThrow(ValidationException::class);
    expect(DB::table('billing_role_entitlements')->count())->toBe(0)->and(BillingMutationIntent::count())->toBe(0)
        ->and($user->fresh()->hasRole('free'))->toBeTrue();
});

it('refuses new-account certification of a reloaded existing user without touching old grants', function () {
    $user = User::factory()->create()->fresh();
    expect(fn () => DB::transaction(fn () => app(BillingRoleEntitlements::class)->grantNewAccountDefault($user, 'registration')))
        ->toThrow(LogicException::class);
    expect(DB::table('billing_role_entitlements')->count())->toBe(0)
        ->and(DB::table('billing_role_entitlement_events')->count())->toBe(0);
});

it('rolls back new registration when its explicit default provenance audit cannot persist', function () {
    $this->withoutExceptionHandling();
    $connection = DB::connection();
    $property = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $before = $property->getValue($connection);
    $connection->beforeExecuting(function (string $sql): void {
        if (preg_match('/\A\s*insert\s+into\s+[`"]?billing_role_entitlement_events/i', $sql)) {
            throw new RuntimeException('Synthetic new-account audit failure.');
        }
    });
    try {
        expect(fn () => $this->post('/register', ['name' => 'Synthetic rollback', 'email' => 'rollback-default@example.test',
            'password' => 'password', 'password_confirmation' => 'password', 'age_verified' => '1']))
            ->toThrow(RuntimeException::class, 'Synthetic new-account audit failure.');
    } finally {
        $property->setValue($connection, $before);
    }
    expect(User::query()->where('email', 'rollback-default@example.test')->count())->toBe(0)
        ->and(DB::table('billing_role_entitlements')->count())->toBe(0)
        ->and(DB::table('billing_role_entitlement_events')->count())->toBe(0);
});
