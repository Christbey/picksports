<?php

use App\Models\User;

it('allows an admin to impersonate another user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonation.start', $target))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session('impersonator_id'))->toBe($admin->id);
});

it('forbids non-admin users from starting impersonation', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.impersonation.start', $target))
        ->assertForbidden();

    $this->assertAuthenticatedAs($user);
    expect(session('impersonator_id'))->toBeNull();
});

it('prevents admin from impersonating their own account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/admin/subscriptions')
        ->post(route('admin.impersonation.start', $admin))
        ->assertRedirect('/admin/subscriptions');

    $this->assertAuthenticatedAs($admin);
    expect(session('impersonator_id'))->toBeNull();
});

it('allows stopping impersonation and restores the original admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonation.start', $target))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session('impersonator_id'))->toBe($admin->id);

    $this->actingAs($target)
        ->post(route('impersonation.stop'))
        ->assertRedirect(route('admin.subscriptions'));

    $this->assertAuthenticatedAs($admin);
    expect(session('impersonator_id'))->toBeNull();
});

it('returns with an error when stopping without an active impersonation session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('impersonation.stop'))
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(session('impersonator_id'))->toBeNull();
});
