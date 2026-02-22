<?php

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\NotificationTemplate;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserAlertSent;
use App\Notifications\DailyBettingDigest;
use Illuminate\Support\Facades\Notification;

uses()->group('alerts', 'commands');

beforeEach(function () {
    Notification::fake();

    // Create tiers
    SubscriptionTier::factory()->create([
        'slug' => 'free',
        'name' => 'Free',
        'is_default' => true,
        'features' => ['email_alerts' => true],
    ]);

    // Seed notification template
    NotificationTemplate::factory()->create([
        'name' => 'Daily Betting Digest',
        'active' => true,
        'subject' => 'Your Daily Digest - {digest.bets_count} picks',
        'email_body' => 'Here are your picks for {digest.date}',
    ]);
});

it('sends digests to qualifying users', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'digest_mode' => 'daily_summary',
        'digest_time' => now()->format('H:i:s'), // Matches current time
        'sports' => ['cbb'],
        'notification_types' => ['email'],
    ]);

    // Create a game with prediction and odds
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now(),
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => [
            'home_team' => $home->school,
            'away_team' => $away->school,
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $home->school, 'point' => -5.5, 'price' => -110],
                                ['name' => $away->school, 'point' => 5.5, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $game->prediction()->create([
        'season' => 2026,
        'home_elo' => 1500,
        'away_elo' => 1500,
        'predicted_spread' => -10.0,
        'predicted_total' => 150.0,
        'win_probability' => 0.75,
        'confidence_score' => 80.0,
    ]);

    $this->artisan('alerts:send-daily-digests', ['--sport' => 'cbb'])
        ->assertSuccessful();

    Notification::assertSentTo($user, DailyBettingDigest::class);

    // Verify alert was tracked
    expect(UserAlertSent::where('user_id', $user->id)->where('alert_type', 'daily_digest')->exists())
        ->toBeTrue();
});

it('skips users with realtime mode', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'digest_mode' => 'realtime', // Not daily_summary
        'sports' => ['cbb'],
        'notification_types' => ['email'],
    ]);

    $this->artisan('alerts:send-daily-digests', ['--sport' => 'cbb'])
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips users outside time window', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'digest_mode' => 'daily_summary',
        'digest_time' => now()->addHours(5)->format('H:i:s'), // Far from current time
        'sports' => ['cbb'],
        'notification_types' => ['email'],
    ]);

    $this->artisan('alerts:send-daily-digests', ['--sport' => 'cbb'])
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('sends empty digest when no games available', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'digest_mode' => 'daily_summary',
        'digest_time' => now()->format('H:i:s'),
        'sports' => ['cbb'],
        'notification_types' => ['email'],
    ]);

    // No games created
    $this->artisan('alerts:send-daily-digests', ['--sport' => 'cbb'])
        ->assertSuccessful();

    // Should still send digest with empty state (per config)
    Notification::assertSentTo($user, DailyBettingDigest::class);
});

it('respects dry-run flag', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'digest_mode' => 'daily_summary',
        'digest_time' => now()->format('H:i:s'),
        'sports' => ['cbb'],
        'notification_types' => ['email'],
    ]);

    $this->artisan('alerts:send-daily-digests', ['--sport' => 'cbb', '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutput('DRY RUN MODE - No emails will be sent');

    Notification::assertNothingSent();
    expect(UserAlertSent::count())->toBe(0);
});
