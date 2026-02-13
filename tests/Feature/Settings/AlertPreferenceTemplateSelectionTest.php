<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserAlertPreference;

test('alert preferences page displays available notification templates', function () {
    $user = User::factory()->create();

    NotificationTemplate::factory()->count(3)->create(['active' => true]);
    NotificationTemplate::factory()->create(['active' => false]);

    $response = $this
        ->actingAs($user)
        ->get('/settings/alert-preferences');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/AlertPreferences')
            ->has('availableTemplates', 3)
        );
});

test('users can save template selections in alert preferences', function () {
    $user = User::factory()->create();

    $template1 = NotificationTemplate::factory()->create(['active' => true]);
    $template2 = NotificationTemplate::factory()->create(['active' => true]);
    $template3 = NotificationTemplate::factory()->create(['active' => true]);

    $response = $this
        ->actingAs($user)
        ->patch('/settings/alert-preferences', [
            'enabled' => true,
            'sports' => ['nfl', 'nba'],
            'notification_types' => ['email'],
            'enabled_template_ids' => [$template1->id, $template3->id],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
            'digest_time' => null,
            'phone_number' => null,
        ]);

    $response->assertRedirect('/settings/alert-preferences')
        ->assertSessionHasNoErrors();

    $preference = UserAlertPreference::where('user_id', $user->id)->first();

    expect($preference->enabled_template_ids)
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain($template1->id)
        ->toContain($template3->id)
        ->not->toContain($template2->id);
});

test('users can save empty template selection to receive all templates', function () {
    $user = User::factory()->create();

    NotificationTemplate::factory()->count(3)->create(['active' => true]);

    $response = $this
        ->actingAs($user)
        ->patch('/settings/alert-preferences', [
            'enabled' => true,
            'sports' => ['nfl'],
            'notification_types' => ['email'],
            'enabled_template_ids' => [],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
            'digest_time' => null,
            'phone_number' => null,
        ]);

    $response->assertRedirect('/settings/alert-preferences')
        ->assertSessionHasNoErrors();

    $preference = UserAlertPreference::where('user_id', $user->id)->first();

    expect($preference->enabled_template_ids)->toBeArray()->toBeEmpty();
});

test('validation fails for invalid template ids', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/settings/alert-preferences', [
            'enabled' => true,
            'sports' => ['nfl'],
            'notification_types' => ['email'],
            'enabled_template_ids' => [999999],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
            'digest_time' => null,
            'phone_number' => null,
        ]);

    $response->assertSessionHasErrors('enabled_template_ids.0');
});

test('shouldReceiveTemplate method returns true when template is in enabled list', function () {
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->create();

    $preference = UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'enabled_template_ids' => [$template->id, 99],
    ]);

    expect($preference->shouldReceiveTemplate($template->id))->toBeTrue();
});

test('shouldReceiveTemplate method returns false when template is not in enabled list', function () {
    $user = User::factory()->create();
    $template1 = NotificationTemplate::factory()->create();
    $template2 = NotificationTemplate::factory()->create();

    $preference = UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'enabled_template_ids' => [$template1->id],
    ]);

    expect($preference->shouldReceiveTemplate($template2->id))->toBeFalse();
});

test('shouldReceiveTemplate method returns true for all templates when list is empty', function () {
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->create();

    $preference = UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'enabled_template_ids' => [],
    ]);

    expect($preference->shouldReceiveTemplate($template->id))->toBeTrue();
});

test('shouldReceiveTemplate method returns false when alerts are disabled', function () {
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->create();

    $preference = UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => false,
        'enabled_template_ids' => [$template->id],
    ]);

    expect($preference->shouldReceiveTemplate($template->id))->toBeFalse();
});
