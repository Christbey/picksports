<?php

use App\Models\User;
use App\Models\UserAlertPreference;

test('guests cannot access alert preferences through api v2', function () {
    $this->getJson('/api/v2/alert-preferences')
        ->assertUnauthorized();
});

test('authenticated users can create alert preferences through api v2', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v2/alert-preferences', [
            'enabled' => true,
            'notification_types' => ['email', 'push'],
            'minimum_edge' => 5,
            'time_window_start' => '08:00',
            'time_window_end' => '22:00',
            'digest_mode' => 'daily_summary',
            'digest_time' => '07:30',
            'daily_digest_subscribed' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.notification_types', ['email', 'push'])
        ->assertJsonPath('data.minimum_edge', '5.00')
        ->assertJsonPath('data.digest_mode', 'daily_summary')
        ->assertJsonPath('data.daily_digest_subscribed', true);
});

test('authenticated users can view and update alert preferences through api v2', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'notification_types' => ['email'],
        'minimum_edge' => 4,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v2/alert-preferences')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.notification_types', ['email']);

    $this->actingAs($user)
        ->putJson('/api/v2/alert-preferences', [
            'enabled' => false,
            'minimum_edge' => 7,
            'daily_digest_subscribed' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.minimum_edge', '7.00')
        ->assertJsonPath('data.daily_digest_subscribed', false);
});
