<?php

use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\AlertService;
use Illuminate\Support\Facades\Notification;

test('edit renders alert preferences page for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('alert-preferences.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/AlertPreferences')
        ->has('preference'));
});

test('edit returns null preference when user has no preferences', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('alert-preferences.edit'));

    $response->assertInertia(fn ($page) => $page
        ->where('preference', null));
});

test('edit returns existing preference when user has preferences', function () {
    $user = User::factory()->create();
    $preference = UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nfl', 'nba'],
        'notification_types' => ['email'],
        'minimum_edge' => 7.5,
    ]);

    $response = $this->actingAs($user)->get(route('alert-preferences.edit'));

    $response->assertInertia(fn ($page) => $page
        ->where('preference.enabled', true)
        ->where('preference.sports', ['nfl', 'nba'])
        ->where('preference.notification_types', ['email'])
        ->where('preference.minimum_edge', '7.50'));
});

test('edit includes admin stats for admin users', function () {
    $admin = User::factory()->admin()->create();
    UserAlertPreference::factory()->count(5)->create(['enabled' => true]);
    UserAlertPreference::factory()->count(3)->create(['enabled' => false]);

    $response = $this->actingAs($admin)->get(route('alert-preferences.edit'));

    $response->assertInertia(fn ($page) => $page
        ->has('adminStats')
        ->where('adminStats.total_users_with_alerts', 5)
        ->where('adminStats.total_preferences', 8));
});

test('edit does not include admin stats for non admin users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('alert-preferences.edit'));

    $response->assertInertia(fn ($page) => $page
        ->missing('adminStats'));
});

test('update creates new preference for user without existing preference', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->patch(route('alert-preferences.update'), [
            'enabled' => true,
            'sports' => ['nfl', 'nba'],
            'notification_types' => ['email', 'push'],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
            'digest_time' => null,
            'phone_number' => null,
        ]);

    $response->assertRedirect(route('alert-preferences.edit'));
    $response->assertSessionHas('status', 'preferences-updated');

    $this->assertDatabaseHas('user_alert_preferences', [
        'user_id' => $user->id,
        'enabled' => true,
        'minimum_edge' => 5.0,
    ]);
});

test('update modifies existing preference', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => false,
        'minimum_edge' => 3.0,
    ]);

    $response = $this->actingAs($user)
        ->patch(route('alert-preferences.update'), [
            'enabled' => true,
            'sports' => ['cfb'],
            'notification_types' => ['sms'],
            'minimum_edge' => 10.0,
            'time_window_start' => '10:00',
            'time_window_end' => '22:00',
            'digest_mode' => 'daily_summary',
            'digest_time' => '08:00',
            'phone_number' => '+1234567890',
        ]);

    $response->assertRedirect(route('alert-preferences.edit'));

    $this->assertDatabaseHas('user_alert_preferences', [
        'user_id' => $user->id,
        'enabled' => true,
        'minimum_edge' => 10.0,
        'phone_number' => '+1234567890',
    ]);
});

test('update validates required fields', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('alert-preferences.edit'))
        ->patch(route('alert-preferences.update'), []);

    $response->assertSessionHasErrors([
        'enabled',
        'sports',
        'notification_types',
        'minimum_edge',
        'time_window_start',
        'time_window_end',
        'digest_mode',
    ]);
});

test('update validates sports are valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('alert-preferences.edit'))
        ->patch(route('alert-preferences.update'), [
            'enabled' => true,
            'sports' => ['invalid_sport'],
            'notification_types' => ['email'],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
        ]);

    $response->assertSessionHasErrors('sports.0');
});

test('update validates notification types are valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('alert-preferences.edit'))
        ->patch(route('alert-preferences.update'), [
            'enabled' => true,
            'sports' => ['nfl'],
            'notification_types' => ['invalid_type'],
            'minimum_edge' => 5.0,
            'time_window_start' => '09:00',
            'time_window_end' => '23:00',
            'digest_mode' => 'realtime',
        ]);

    $response->assertSessionHasErrors('notification_types.0');
});

test('check alerts returns 403 for non admin users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('alert-preferences.check'));

    $response->assertForbidden();
});

test('check alerts processes all sports when no sport specified', function () {
    $admin = User::factory()->admin()->create();

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('checkAllSports')
        ->once()
        ->andReturn([
            'nfl' => 2,
            'nba' => 3,
            'cbb' => 1,
            'wcbb' => 0,
            'mlb' => 1,
            'cfb' => 2,
            'wnba' => 0,
        ]);

    $this->app->instance(AlertService::class, $alertService);

    $response = $this->actingAs($admin)
        ->post(route('alert-preferences.check'));

    $response->assertRedirect();
    $response->assertSessionHas('flash.data.message', 'Checked all sports - sent 9 total alert(s)');
});

test('check alerts processes specific sport when sport specified', function () {
    $admin = User::factory()->admin()->create();

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('checkForValueOpportunities')
        ->with('nfl')
        ->once()
        ->andReturn(5);

    $this->app->instance(AlertService::class, $alertService);

    $response = $this->actingAs($admin)
        ->post(route('alert-preferences.check'), [
            'sport' => 'nfl',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash.data.message', 'Checked nfl - sent 5 alert(s)');
});
