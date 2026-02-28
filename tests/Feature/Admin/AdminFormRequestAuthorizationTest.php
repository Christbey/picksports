<?php

use App\Http\Requests\Admin\NotificationTemplateRequest;
use App\Http\Requests\Admin\TierRequest;
use App\Models\User;

it('tier request authorize returns false without authenticated user', function () {
    $request = TierRequest::create('/admin/tiers', 'POST');
    $request->setUserResolver(fn () => null);

    expect($request->authorize())->toBeFalse();
});

it('tier request authorize returns false for non-admin user', function () {
    $user = User::factory()->create();

    $request = TierRequest::create('/admin/tiers', 'POST');
    $request->setUserResolver(fn () => $user);

    expect($request->authorize())->toBeFalse();
});

it('tier request authorize returns true for admin user', function () {
    $admin = User::factory()->admin()->create();

    $request = TierRequest::create('/admin/tiers', 'POST');
    $request->setUserResolver(fn () => $admin);

    expect($request->authorize())->toBeTrue();
});

it('notification template request authorize returns false without authenticated user', function () {
    $request = NotificationTemplateRequest::create('/admin/notification-templates', 'POST');
    $request->setUserResolver(fn () => null);

    expect($request->authorize())->toBeFalse();
});

it('notification template request authorize returns false for non-admin user', function () {
    $user = User::factory()->create();

    $request = NotificationTemplateRequest::create('/admin/notification-templates', 'POST');
    $request->setUserResolver(fn () => $user);

    expect($request->authorize())->toBeFalse();
});

it('notification template request authorize returns true for admin user', function () {
    $admin = User::factory()->admin()->create();

    $request = NotificationTemplateRequest::create('/admin/notification-templates', 'POST');
    $request->setUserResolver(fn () => $admin);

    expect($request->authorize())->toBeTrue();
});
