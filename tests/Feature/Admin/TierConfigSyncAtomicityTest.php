<?php

use App\Models\SubscriptionTier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

it('rolls back all configured tier and role writes when a later tier is refused', function () {
    $tier = config('subscriptions.tiers.free');
    config(['subscriptions.tiers' => ['free' => $tier, 'admin' => [...$tier, 'name' => 'Reserved']], 'subscriptions.default_tier' => 'free']);
    expect(fn () => Artisan::call('tiers:sync'))->toThrow(ValidationException::class);
    expect(SubscriptionTier::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0);
});

it('commits configured tiers and their roles together on success', function () {
    $tier = config('subscriptions.tiers.free');
    config(['subscriptions.tiers' => ['free' => $tier, 'synthetic' => [...$tier, 'name' => 'Synthetic']], 'subscriptions.default_tier' => 'free']);
    $this->artisan('tiers:sync')->assertSuccessful();
    expect(SubscriptionTier::query()->orderBy('sort_order')->pluck('slug')->all())->toBe(['free', 'synthetic'])
        ->and(Role::query()->orderBy('id')->pluck('name')->all())->toBe(['free', 'synthetic'])
        ->and(SubscriptionTier::query()->where('slug', 'free')->sole()->is_default)->toBeTrue();
});

it('refuses malformed tier configuration before database writes', function () {
    config(['subscriptions.tiers' => null]);
    $this->artisan('tiers:sync')->assertFailed();
    expect(SubscriptionTier::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0);
});
