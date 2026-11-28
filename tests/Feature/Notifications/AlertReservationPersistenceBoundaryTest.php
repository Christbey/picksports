<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NotificationDelivery;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Services\AlertService;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->artisan('tiers:sync');
    config(['prediction_lifecycle.canonical_reads.nba' => false, 'alerts.opportunity.unit_thresholds.points' => 0]);
    Notification::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    UserAlertSent::flushEventListeners();
    $this->travelBack();
});

function reservationBoundaryFixture(float $edge = 8): array
{
    $user = User::factory()->create();
    $tier = SubscriptionTier::query()->where('slug', 'basic')->firstOrFail();
    $price = $tier->stripe_price_id_monthly ?: 'synthetic_reservation_boundary';
    $tier->update(['stripe_price_id_monthly' => $price]);
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_boundary_'.$user->id, 'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1]);
    UserAlertPreference::factory()->create([
        'user_id' => $user->id, 'enabled' => true, 'sports' => ['nba'], 'notification_types' => ['email'],
        'minimum_edge' => 0, 'time_window_start' => '00:00', 'time_window_end' => '23:59', 'digest_mode' => 'realtime',
    ]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => now()->addDay(), 'status' => 'scheduled', 'odds_data' => ['bookmakers' => []], 'odds_updated_at' => now()]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $recommendations = Mockery::mock(GameBettingRecommendationService::class);
    $recommendations->shouldReceive('forPrediction')->andReturn([[
        'type' => 'moneyline', 'edge_unit' => 'percentage_points', 'edge' => $edge, 'recommendation' => 'Synthetic home ML',
    ]]);
    app()->instance(GameBettingRecommendationService::class, $recommendations);

    return [$user, $prediction, app(AlertService::class)];
}

it('reserves a real eligible alert once before fake delivery and preserves deduplication', function () {
    [$user, $prediction, $service] = reservationBoundaryFixture();
    expect($service->checkForValueOpportunities('nba'))->toBe(1)
        ->and($service->checkForValueOpportunities('nba'))->toBe(0);
    $row = UserAlertSent::query()->useWritePdo()->sole();
    expect($row->user_id)->toBe($user->id)->and($row->prediction_id)->toBe($prediction->id)
        ->and($row->prediction_type)->toBe($prediction::class)->and($row->sport)->toBe('nba')
        ->and($row->deduplication_key)->toHaveLength(64)
        ->and(NotificationDelivery::query()->sole()->status)->toBe('dispatched');
    Notification::assertSentToTimes($user, BettingValueAlert::class, 1);
    Http::assertNothingSent();
});

it('does not dispatch or acknowledge a cancelled reservation save', function (string $event) {
    [$user, $prediction, $service] = reservationBoundaryFixture();
    UserAlertSent::{$event}(fn () => false);
    $error = null;
    try {
        $service->checkForValueOpportunities('nba');
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    Notification::assertNothingSent();
    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and(UserAlertSent::query()->useWritePdo()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['creating', 'saving']);

it('preserves existing fractional edge rounding in the persisted reservation and unchanged rerun', function () {
    [$user, $prediction, $service] = reservationBoundaryFixture(8.125);
    expect($service->checkForValueOpportunities('nba'))->toBe(1)
        ->and($service->checkForValueOpportunities('nba'))->toBe(0);
    $row = UserAlertSent::query()->useWritePdo()->sole();
    expect($row->expected_value)->toBe('8.13')
        ->and($row->user_id)->toBe($user->id)
        ->and($row->prediction_id)->toBe($prediction->id);
    Notification::assertSentTo($user, BettingValueAlert::class, fn ($notification): bool => $notification->expectedValue === 8.13);
    Notification::assertSentToTimes($user, BettingValueAlert::class, 1);
    Http::assertNothingSent();
});

it('checks the requested persisted reservation header before fake delivery', function (string $intervention) {
    [$user, $prediction, $service] = reservationBoundaryFixture();
    $other = User::factory()->create();
    if ($intervention === 'delete_after_create') {
        UserAlertSent::created(function (UserAlertSent $row): void {
            $row->getConnection()->table('user_alerts_sent')->where('id', $row->id)->delete();
        });
    } else {
        UserAlertSent::creating(function (UserAlertSent $row) use ($intervention, $other): void {
            if ($intervention === 'rewrite_owner') {
                $row->user_id = $other->id;
            } else {
                $row->deduplication_key = str_repeat('a', 64);
            }
        });
    }
    $error = null;
    try {
        $service->checkForValueOpportunities('nba');
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    Notification::assertNothingSent();
    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and(UserAlertSent::query()->useWritePdo()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['rewrite_owner', 'rewrite_identity', 'delete_after_create']);
