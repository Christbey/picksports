<?php

use App\Models\User;
use App\Models\UserAlertPreference;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->preference = UserAlertPreference::factory()->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'sports' => ['nfl', 'nba'],
        'notification_types' => ['email', 'push'],
        'minimum_edge' => 5.0,
        'time_window_start' => '09:00:00',
        'time_window_end' => '23:00:00',
        'digest_mode' => 'realtime',
    ]);
});

test('should receive email notifications returns true when email is in notification types', function () {
    expect($this->preference->shouldReceiveEmailNotifications())->toBeTrue();
});

test('should receive email notifications returns false when alerts are disabled', function () {
    $this->preference->enabled = false;

    expect($this->preference->shouldReceiveEmailNotifications())->toBeFalse();
});

test('should receive email notifications returns false when email is not in notification types', function () {
    $this->preference->notification_types = ['push'];

    expect($this->preference->shouldReceiveEmailNotifications())->toBeFalse();
});

test('should receive push notifications returns true when push is in notification types', function () {
    expect($this->preference->shouldReceivePushNotifications())->toBeTrue();
});

test('should receive push notifications returns false when alerts are disabled', function () {
    $this->preference->enabled = false;

    expect($this->preference->shouldReceivePushNotifications())->toBeFalse();
});

test('should receive sms notifications returns true when sms is in notification types', function () {
    $this->preference->notification_types = ['sms'];
    $this->preference->phone_number = '+1234567890';

    expect($this->preference->shouldReceiveSmsNotifications())->toBeTrue();
});

test('is within time window returns true when current time is within window', function () {
    $this->travelTo(now()->setTime(12, 0));

    expect($this->preference->isWithinTimeWindow())->toBeTrue();
});

test('is within time window returns false when current time is before window', function () {
    $this->travelTo(now()->setTime(8, 0));

    expect($this->preference->isWithinTimeWindow())->toBeFalse();
});

test('is within time window returns false when current time is after window', function () {
    $this->travelTo(now()->setTime(23, 30));

    expect($this->preference->isWithinTimeWindow())->toBeFalse();
});

test('is interested in sport returns true when sport is in list', function () {
    expect($this->preference->isInterestedInSport('nfl'))->toBeTrue();
    expect($this->preference->isInterestedInSport('nba'))->toBeTrue();
});

test('is interested in sport returns false when sport is not in list', function () {
    expect($this->preference->isInterestedInSport('mlb'))->toBeFalse();
});

test('is interested in sport is case insensitive', function () {
    expect($this->preference->isInterestedInSport('NFL'))->toBeTrue();
    expect($this->preference->isInterestedInSport('NbA'))->toBeTrue();
});

test('has sufficient edge returns true when value meets minimum', function () {
    expect($this->preference->hasSufficientEdge(5.0))->toBeTrue();
    expect($this->preference->hasSufficientEdge(6.0))->toBeTrue();
});

test('has sufficient edge returns false when value is below minimum', function () {
    expect($this->preference->hasSufficientEdge(4.9))->toBeFalse();
});

test('should send alert to user returns true when all conditions are met', function () {
    $this->travelTo(now()->setTime(12, 0));

    expect($this->preference->shouldSendAlertToUser('nfl', 5.5))->toBeTrue();
});

test('should send alert to user returns false when user not interested in sport', function () {
    $this->travelTo(now()->setTime(12, 0));

    expect($this->preference->shouldSendAlertToUser('mlb', 5.5))->toBeFalse();
});

test('should send alert to user returns false when edge is insufficient', function () {
    $this->travelTo(now()->setTime(12, 0));

    expect($this->preference->shouldSendAlertToUser('nfl', 4.0))->toBeFalse();
});

test('should send alert to user returns false when outside time window', function () {
    $this->travelTo(now()->setTime(23, 30));

    expect($this->preference->shouldSendAlertToUser('nfl', 5.5))->toBeFalse();
});

test('should send alert to user returns false when digest mode is daily summary', function () {
    $this->preference->digest_mode = 'daily_summary';
    $this->travelTo(now()->setTime(12, 0));

    expect($this->preference->shouldSendAlertToUser('nfl', 5.5))->toBeFalse();
});

test('belongs to user relationship works', function () {
    expect($this->preference->user)->toBeInstanceOf(User::class);
    expect($this->preference->user->id)->toBe($this->user->id);
});

test('sports attribute is cast to array', function () {
    expect($this->preference->sports)->toBeArray();
    expect($this->preference->sports)->toContain('nfl', 'nba');
});

test('notification types attribute is cast to array', function () {
    expect($this->preference->notification_types)->toBeArray();
    expect($this->preference->notification_types)->toContain('email', 'push');
});
