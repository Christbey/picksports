<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Sync tiers from config to database
    $this->artisan('tiers:sync');
});

test('free tier users do not receive email alerts', function () {
    Notification::fake();

    $freeTier = SubscriptionTier::where('slug', 'free')->first();
    $user = User::factory()->create();

    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    $homeTeam = Team::factory()->create(['school' => 'Lakers']);
    $awayTeam = Team::factory()->create(['school' => 'Celtics']);

    $game = Game::factory()->create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => [
            'home_team' => 'Lakers',
            'away_team' => 'Celtics',
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => 'Lakers', 'point' => -5.5, 'price' => -110],
                                ['name' => 'Celtics', 'point' => 5.5, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $prediction = Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -8.5,
    ]);

    $alertService = app(AlertService::class);
    $alertsSent = $alertService->checkForValueOpportunities('nba');

    expect($alertsSent)->toBe(0);
    Notification::assertNotSentTo($user, BettingValueAlert::class);
});

test('basic tier users receive email alerts for allowed sports', function () {
    Notification::fake();

    $basicTier = SubscriptionTier::where('slug', 'basic')->first();
    $user = User::factory()->create();

    // Simulate subscription by creating a subscription record
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $basicTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    $homeTeam = Team::factory()->create(['school' => 'Lakers']);
    $awayTeam = Team::factory()->create(['school' => 'Celtics']);

    $game = Game::factory()->create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => [
            'home_team' => 'Lakers',
            'away_team' => 'Celtics',
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => 'Lakers', 'point' => -5.5, 'price' => -110],
                                ['name' => 'Celtics', 'point' => 5.5, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $prediction = Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -8.5,
    ]);

    $alertService = app(AlertService::class);
    $alertsSent = $alertService->checkForValueOpportunities('nba');

    expect($alertsSent)->toBeGreaterThan(0);
    Notification::assertSentTo($user, BettingValueAlert::class);

    // Verify alert was recorded
    expect(UserAlertSent::where('user_id', $user->id)->count())->toBe(1);
});

test('users cannot receive alerts for sports outside their tier access', function () {
    Notification::fake();

    $basicTier = SubscriptionTier::where('slug', 'basic')->first();
    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $basicTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['wnba'], // Basic tier does NOT have WNBA access
        'notification_types' => ['email'],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    // We would need WNBA models for a complete test, but the logic will prevent it
    // This test verifies the tier sports_access filtering works

    expect($user->canAccessSport('wnba'))->toBeFalse();
    expect($user->canAccessSport('nba'))->toBeTrue();
});

test('users cannot exceed daily alert limit from their tier', function () {
    Notification::fake();

    $basicTier = SubscriptionTier::where('slug', 'basic')->first();
    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $basicTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    // Simulate user has already received 25 alerts today (basic tier limit)
    $limit = $basicTier->features['predictions_per_day'];
    for ($i = 0; $i < $limit; $i++) {
        UserAlertSent::create([
            'user_id' => $user->id,
            'sport' => 'nba',
            'alert_type' => 'betting_value',
            'expected_value' => 7.5,
            'sent_at' => now(),
        ]);
    }

    expect($user->hasReachedDailyAlertLimit())->toBeTrue();
    expect(UserAlertSent::getTodayCountForUser($user->id))->toBe($limit);
});

test('pro tier users have unlimited alerts', function () {
    $proTier = SubscriptionTier::where('slug', 'pro')->first();
    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $proTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    // Create 1000 sent alerts
    for ($i = 0; $i < 1000; $i++) {
        UserAlertSent::create([
            'user_id' => $user->id,
            'sport' => 'nba',
            'alert_type' => 'betting_value',
            'expected_value' => 7.5,
            'sent_at' => now(),
        ]);
    }

    // Pro tier has null limit (unlimited)
    expect($user->getDailyAlertLimit())->toBeNull();
    expect($user->hasReachedDailyAlertLimit())->toBeFalse();
});

test('user tier feature checks work correctly', function () {
    $freeTier = SubscriptionTier::where('slug', 'free')->first();
    $basicTier = SubscriptionTier::where('slug', 'basic')->first();

    $freeUser = User::factory()->create();
    $basicUser = User::factory()->create();

    $basicUser->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $basicTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    expect($freeUser->hasTierFeature('email_alerts'))->toBeFalse();
    expect($freeUser->hasTierFeature('advanced_analytics'))->toBeFalse();
    expect($freeUser->hasTierFeature('export_predictions'))->toBeFalse();

    expect($basicUser->hasTierFeature('email_alerts'))->toBeTrue();
    expect($basicUser->hasTierFeature('export_predictions'))->toBeTrue();
    expect($basicUser->hasTierFeature('advanced_analytics'))->toBeFalse(); // Still false for basic
});

test('alert sent records include all required data', function () {
    Notification::fake();

    $basicTier = SubscriptionTier::where('slug', 'basic')->first();
    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => $basicTier->stripe_price_id_monthly,
        'quantity' => 1,
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    $homeTeam = Team::factory()->create(['school' => 'Lakers']);
    $awayTeam = Team::factory()->create(['school' => 'Celtics']);

    $game = Game::factory()->create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => [
            'home_team' => 'Lakers',
            'away_team' => 'Celtics',
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => 'Lakers', 'point' => -5.5, 'price' => -110],
                                ['name' => 'Celtics', 'point' => 5.5, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $prediction = Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -8.5,
    ]);

    $alertService = app(AlertService::class);
    $alertService->checkForValueOpportunities('nba');

    $sentAlert = UserAlertSent::where('user_id', $user->id)->first();

    expect($sentAlert)->not->toBeNull()
        ->and($sentAlert->user_id)->toBe($user->id)
        ->and($sentAlert->sport)->toBe('nba')
        ->and($sentAlert->alert_type)->toBe('betting_value')
        ->and($sentAlert->prediction_id)->toBe($prediction->id)
        ->and($sentAlert->prediction_type)->toBe(get_class($prediction))
        ->and($sentAlert->expected_value)->toBeGreaterThan(0)
        ->and($sentAlert->sent_at)->not->toBeNull();
});
