<?php

use App\Models\SubscriptionTier;
use App\Models\User;

it('requires authentication for representative admin pages', function (string $path) {
    $this->get($path)
        ->assertRedirect(route('login'));
})->with([
    '/admin/subscriptions',
    '/admin/permissions',
    '/admin/healthchecks',
    '/admin/tiers',
    '/admin/tiers/create',
]);

it('forbids non-admin users from representative admin pages', function (string $path) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($path)
        ->assertForbidden();
})->with([
    '/admin/subscriptions',
    '/admin/permissions',
    '/admin/healthchecks',
    '/admin/tiers',
    '/admin/tiers/create',
]);

it('allows admin users to access representative admin pages', function (string $path) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get($path)
        ->assertOk();
})->with([
    '/admin/subscriptions',
    '/admin/permissions',
    '/admin/healthchecks',
    '/admin/tiers',
    '/admin/tiers/create',
]);

it('forbids non-admin users from admin mutation routes', function (string $method, string $path, array $payload) {
    $user = User::factory()->create();

    $response = match ($method) {
        'POST' => $this->actingAs($user)->post($path, $payload),
        'PATCH' => $this->actingAs($user)->patch($path, $payload),
        default => throw new InvalidArgumentException("Unsupported method: {$method}"),
    };

    $response->assertForbidden();
})->with([
    ['POST', '/admin/healthchecks/run', []],
    ['POST', '/admin/healthchecks/sync', []],
    ['POST', '/admin/subscriptions/sync-all', []],
    ['POST', '/admin/tiers', ['name' => 'Test Tier', 'slug' => 'test-tier']],
]);

it('forbids non-admin users from updating tiers', function () {
    $user = User::factory()->create();
    $tier = SubscriptionTier::query()->create([
        'name' => 'Starter',
        'slug' => 'starter',
    ]);

    $this->actingAs($user)
        ->patch("/admin/tiers/{$tier->id}", [
            'name' => 'Updated Starter',
            'slug' => 'starter-updated',
        ])
        ->assertForbidden();
});
