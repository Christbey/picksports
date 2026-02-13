<?php

use App\Listeners\StartUserOnboarding;
use App\Models\User;
use App\Notifications\Onboarding\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('onboarding starts automatically when user registers', function () {
    $user = User::factory()->create();

    event(new Registered($user));

    $this->assertDatabaseHas('user_onboarding_progress', [
        'user_id' => $user->id,
        'current_step' => 'welcome',
        'progress_percentage' => 0,
    ]);
});

test('registered event listener is attached', function () {
    Event::fake();

    Event::assertListening(
        Registered::class,
        StartUserOnboarding::class
    );
});

test('onboarding listener schedules welcome emails on registration', function () {
    Notification::fake();

    $user = User::factory()->create();

    event(new Registered($user));

    Notification::assertSentTo($user, WelcomeEmail::class);
});
