<?php

use App\Listeners\NotificationDeliverySubscriber;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NotificationDelivery;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserAlertSent;
use App\Models\ValidationRun;
use App\Notifications\BettingValueAlert;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\ValidationRegressionAlert;
use App\Services\AlertService;
use App\Services\BettingAlertEligibility;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\NotificationDeliveryService;
use App\Services\TwilioWhatsAppService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->artisan('tiers:sync');
    config(['prediction_lifecycle.canonical_reads.nba' => false, 'alerts.opportunity.unit_thresholds.points' => 0]);
});

afterEach(fn () => $this->travelBack());

function safeAlertUser(array $preferences = []): User
{
    $user = User::factory()->create();
    $tier = SubscriptionTier::query()->where('slug', 'basic')->firstOrFail();
    $price = $tier->stripe_price_id_monthly ?: 'test_delivery_safety';
    $tier->update(['stripe_price_id_monthly' => $price]);
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_safety_'.$user->id, 'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1]);
    UserAlertPreference::factory()->create([
        'user_id' => $user->id, 'enabled' => true, 'sports' => ['nba'], 'notification_types' => ['email'],
        'minimum_edge' => 0, 'time_window_start' => '00:00', 'time_window_end' => '23:59', 'digest_mode' => 'realtime', ...$preferences,
    ]);

    return $user;
}

function safeAlertNotification(): BettingValueAlert
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => now()->addDay(), 'status' => 'scheduled']);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);

    return new BettingValueAlert($prediction, 'nba', 8, 'Bet home ML', null, null, ['type' => 'moneyline', 'edge_unit' => 'percentage_points', 'odds_updated_at' => now()->toIso8601String()]);
}

it('rechecks current consent after an alert was queued', function (array $changes) {
    $user = safeAlertUser();
    $notification = safeAlertNotification();
    expect($notification->shouldSend($user, 'mail'))->toBeTrue();
    $user->alertPreference->update($changes);
    expect($notification->shouldSend($user, 'mail'))->toBeFalse()
        ->and(NotificationDelivery::query()->sole()->status)->toBe('suppressed');
})->with([
    'disabled' => [['enabled' => false]],
    'channel removed' => [['notification_types' => ['push']]],
    'summary only' => [['digest_mode' => 'daily_summary']],
    'sport removed' => [['sports' => ['nfl']]],
]);

it('does not apply a percentage threshold to point differences', function () {
    $user = safeAlertUser(['minimum_edge' => 5]);
    config(['alerts.opportunity.unit_thresholds.points' => null]);
    $policy = app(BettingAlertEligibility::class);
    expect($policy->reason($user, 'nba', 8, ['type' => 'spread', 'edge_unit' => 'points']))->toBe('edge_threshold_unit_unconfigured');
    config(['alerts.opportunity.unit_thresholds.points' => 7]);
    expect($policy->reason($user, 'nba', 8, ['type' => 'spread', 'edge_unit' => 'points']))->toBeNull()
        ->and($policy->reason($user, 'nba', 4, ['type' => 'moneyline', 'edge_unit' => 'percentage_points']))->toBe('below_edge_threshold');
});

it('honors the global email switch without disabling push', function () {
    $user = safeAlertUser(['notification_types' => ['email', 'push']]);
    config(['subscriptions.features.email_alerts_enabled' => false]);
    $policy = app(BettingAlertEligibility::class);
    $selection = ['type' => 'moneyline', 'edge_unit' => 'percentage_points'];
    expect($policy->reason($user, 'nba', 8, $selection, null, 'mail'))->toBe('channel_disabled')
        ->and($policy->reason($user, 'nba', 8, $selection, null, 'database'))->toBeNull();
});

it('rechecks tier access when a notification leaves the queue', function () {
    $user = safeAlertUser();
    $notification = safeAlertNotification();
    expect($notification->shouldSend($user, 'mail'))->toBeTrue();
    $user->subscriptions()->delete();
    expect($notification->shouldSend($user, 'mail'))->toBeFalse()
        ->and(NotificationDelivery::query()->sole()->reason_code)->toBe('tier_restricted');
});

it('suppresses legacy queued notifications with unknown edge units', function () {
    $user = safeAlertUser();
    $notification = safeAlertNotification();
    $notification->selection = ['odds_updated_at' => now()->toIso8601String()];
    expect($notification->shouldSend($user, 'mail'))->toBeFalse()
        ->and(NotificationDelivery::query()->sole()->reason_code)->toBe('edge_threshold_unit_unconfigured');
});

it('requires a fresh actual quote observation for legacy queued alerts', function (?string $observation, string $reason) {
    $user = safeAlertUser();
    $notification = safeAlertNotification();
    $notification->selection['odds_updated_at'] = $observation;
    expect($notification->shouldSend($user, 'mail'))->toBeFalse()
        ->and(NotificationDelivery::query()->sole()->reason_code)->toBe($reason);
})->with([
    'missing' => [null, 'missing_quote_observation'],
    'stale' => ['2020-01-01T00:00:00Z', 'stale_or_invalid_quote_observation'],
    'future' => ['2099-01-01T00:00:00Z', 'stale_or_invalid_quote_observation'],
    'invalid' => ['not-a-time', 'stale_or_invalid_quote_observation'],
]);

it('supports an overnight delivery window', function () {
    $user = safeAlertUser(['time_window_start' => '22:00', 'time_window_end' => '06:00']);
    $this->travelTo(now()->setTime(23, 0));
    expect($user->alertPreference->isWithinTimeWindow())->toBeTrue();
    $this->travelTo(now()->setTime(4, 0));
    expect($user->alertPreference->isWithinTimeWindow())->toBeTrue();
    $this->travelTo(now()->setTime(12, 0));
    expect($user->alertPreference->isWithinTimeWindow())->toBeFalse();
});

it('deduplicates an opportunity before reserving daily allowance again', function () {
    Notification::fake();
    $user = safeAlertUser();
    $notification = safeAlertNotification();
    $notification->prediction->game->update(['odds_data' => ['bookmakers' => []], 'odds_updated_at' => now()]);
    $recommendations = Mockery::mock(GameBettingRecommendationService::class);
    $recommendations->shouldReceive('forPrediction')->andReturn([[
        'type' => 'moneyline', 'edge_unit' => 'percentage_points', 'edge' => 8, 'recommendation' => 'Bet home ML',
    ]]);
    app()->instance(GameBettingRecommendationService::class, $recommendations);
    $service = app(AlertService::class);
    expect($service->checkForValueOpportunities('nba'))->toBe(1)
        ->and($service->checkForValueOpportunities('nba'))->toBe(0)
        ->and(UserAlertSent::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->sole()->status)->toBe('dispatched');
});

it('does not count opportunities when no delivery channel is enabled', function () {
    Notification::fake();
    safeAlertUser(['notification_types' => []]);
    $notification = safeAlertNotification();
    $notification->prediction->game->update(['odds_data' => ['bookmakers' => []], 'odds_updated_at' => now()]);
    $recommendations = Mockery::mock(GameBettingRecommendationService::class);
    $recommendations->shouldReceive('forPrediction')->andReturn([[
        'type' => 'moneyline', 'edge_unit' => 'percentage_points', 'edge' => 8, 'recommendation' => 'Bet home ML',
    ]]);
    app()->instance(GameBettingRecommendationService::class, $recommendations);
    expect(app(AlertService::class)->checkForValueOpportunities('nba'))->toBe(0)
        ->and(UserAlertSent::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('claims one transport attempt without retrying an uncertain failed outcome', function () {
    $subscriber = app(NotificationDeliverySubscriber::class);
    $user = User::factory()->create();
    $notification = new ResetPassword('test-token');
    $notification->id = 'stable-queued-notification';
    $event = new NotificationSending($user, $notification, 'mail');
    expect($subscriber->sending($event))->toBeNull()->and($subscriber->sending($event))->toBeFalse();
    $record = NotificationDelivery::query()->sole();
    app(NotificationDeliveryService::class)->markFailed($record, 'transport_failed');
    expect($subscriber->sending($event))->toBeFalse()
        ->and($record->fresh()->status)->toBe('outcome_unknown');
    $subscriber->sent(new NotificationSent($user, $notification, 'mail', null));
    expect($subscriber->sending($event))->toBeFalse()->and($record->fresh()->status)->toBe('accepted');
});

it('deduplicates repeated validation notifications for the same run and recipient', function () {
    $subscriber = app(NotificationDeliverySubscriber::class);
    $admin = User::factory()->create(['is_admin' => true]);
    $run = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'failing',
        'summary' => ['failing' => 1], 'started_at' => now(), 'completed_at' => now(),
    ]);
    $first = new ValidationRegressionAlert($run, ['failing' => 1, 'warning' => 0, 'passing' => -1]);
    $first->id = 'first-dispatch';
    $second = clone $first;
    $second->id = 'second-dispatch';
    expect($subscriber->sending(new NotificationSending($admin, $first, 'mail')))->toBeNull();
    $subscriber->sent(new NotificationSent($admin, $first, 'mail', null));
    expect($subscriber->sending(new NotificationSending($admin, $second, 'mail')))->toBeFalse()
        ->and(NotificationDelivery::query()->count())->toBe(1);
});

it('records framework notification acceptance without storing recipient addresses', function () {
    Mail::fake();
    $user = User::factory()->create();
    $user->notify(new ResetPassword('test-token'));
    $record = NotificationDelivery::query()->sole();
    expect($record->status)->toBe('accepted')->and($record->type)->toBe('ResetPassword')
        ->and($record->recipient_key)->toBe(hash('sha256', strtolower($user->email)))
        ->and($record->accepted_at)->not->toBeNull();
});

it('suppresses framework mail without a recipient route', function () {
    $user = User::factory()->create();
    $user->email = '';
    $notification = new ResetPassword('test-token');
    $notification->id = 'missing-route';
    expect(app(NotificationDeliverySubscriber::class)->sending(new NotificationSending($user, $notification, 'mail')))->toBeFalse()
        ->and(NotificationDelivery::query()->sole()->reason_code)->toBe('missing_recipient');
});

it('preserves accepted delivery timestamps across repeated event handling', function () {
    $service = app(NotificationDeliveryService::class);
    $record = $service->record('test', 'recipient', 'mail', 'test-key');
    $service->markAccepted($record);
    $accepted = $record->fresh()->accepted_at;
    $this->travelTo(now()->addHour());
    $service->markAccepted($record);
    $service->markFailed($record, 'late_failure');
    expect($record->fresh()->status)->toBe('accepted')->and($record->fresh()->accepted_at->equalTo($accepted))->toBeTrue();
});

it('fails a whatsapp job when the provider did not accept it', function () {
    $user = safeAlertUser(['notification_types' => ['whatsapp'], 'phone_number' => '+15555550123']);
    $transport = Mockery::mock(TwilioWhatsAppService::class);
    $transport->shouldReceive('sendMessage')->once()->andReturn(false);
    expect(fn () => (new WhatsAppChannel($transport))->send($user, safeAlertNotification()))->toThrow(RuntimeException::class);
});
