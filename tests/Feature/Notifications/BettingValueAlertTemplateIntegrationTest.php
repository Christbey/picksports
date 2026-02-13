<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Notifications\BettingValueAlert;
use Illuminate\Support\Facades\Notification;

test('notification uses template content when template is provided', function () {
    $user = User::factory()->create(['name' => 'John Doe']);

    $template = NotificationTemplate::factory()->create([
        'name' => 'Betting Value Alert',
        'active' => true,
        'subject' => 'Alert: {prediction.edge_percentage} Edge on {prediction.game_description}',
        'email_body' => 'Hi {user.name}, we found a {prediction.edge_percentage} edge on {prediction.game_description}. Confidence: {prediction.confidence}',
    ]);

    $game = \App\Models\NBA\Game::create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'odds_data' => json_decode('{"home_team":"Lakers","away_team":"Celtics","bookmakers":[]}'),
        'home_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Lakers'])->id,
        'away_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Celtics'])->id,
    ]);

    $prediction = \App\Models\NBA\Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -5.5,
    ]);

    $notification = new BettingValueAlert(
        $prediction,
        'nba',
        7.5,
        'Bet HOME (Lakers) at -5.5',
        $template
    );

    $mailMessage = $notification->toMail($user);

    expect($mailMessage->subject)->toContain('+7.5%')
        ->and($mailMessage->subject)->toContain('Celtics @ Lakers')
        ->and($mailMessage->introLines[0])->toContain('John Doe')
        ->and($mailMessage->introLines[0])->toContain('+7.5%')
        ->and($mailMessage->introLines[0])->toContain('85%');
});

test('notification falls back to hardcoded content when no template provided', function () {
    $user = User::factory()->create();

    $game = \App\Models\NBA\Game::create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'odds_data' => json_decode('{"home_team":"Lakers","away_team":"Celtics","bookmakers":[]}'),
        'home_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Lakers'])->id,
        'away_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Celtics'])->id,
    ]);

    $prediction = \App\Models\NBA\Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -5.5,
    ]);

    $notification = new BettingValueAlert(
        $prediction,
        'nba',
        7.5,
        'Bet HOME (Lakers) at -5.5',
        null
    );

    $mailMessage = $notification->toMail($user);

    expect($mailMessage->subject)->toContain('High-Value Betting Opportunity')
        ->and($mailMessage->subject)->toContain('Celtics @ Lakers')
        ->and($mailMessage->greeting)->toContain('Value Alert: 7.5% Expected Value');
});

test('notification includes push notification data with template', function () {
    $user = User::factory()->create(['name' => 'Jane Smith']);

    $template = NotificationTemplate::factory()->create([
        'name' => 'Betting Value Alert',
        'active' => true,
        'push_title' => 'New Alert: {prediction.edge_percentage}',
        'push_body' => '{user.name}, check out {prediction.game_description}',
    ]);

    $game = \App\Models\NBA\Game::create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
        'odds_data' => json_decode('{"home_team":"Lakers","away_team":"Celtics","bookmakers":[]}'),
        'home_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Lakers'])->id,
        'away_team_id' => \App\Models\NBA\Team::factory()->create(['school' => 'Celtics'])->id,
    ]);

    $prediction = \App\Models\NBA\Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -5.5,
    ]);

    $notification = new BettingValueAlert(
        $prediction,
        'nba',
        7.5,
        'Bet HOME (Lakers) at -5.5',
        $template
    );

    $arrayData = $notification->toArray($user);

    expect($arrayData)->toHaveKey('title')
        ->and($arrayData)->toHaveKey('body')
        ->and($arrayData['title'])->toContain('+7.5%')
        ->and($arrayData['body'])->toContain('Jane Smith')
        ->and($arrayData['body'])->toContain('Celtics @ Lakers');
});

test('alert service respects user template preferences', function () {
    Notification::fake();

    $template = NotificationTemplate::factory()->create([
        'name' => 'Betting Value Alert',
        'active' => true,
    ]);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    UserAlertPreference::factory()->create([
        'user_id' => $user1->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'enabled_template_ids' => [$template->id],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user2->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'enabled_template_ids' => [],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    $game = \App\Models\NBA\Game::create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
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
        'home_team_id' => \App\Models\NBA\Team::factory()->create(['name' => 'Lakers'])->id,
        'away_team_id' => \App\Models\NBA\Team::factory()->create(['name' => 'Celtics'])->id,
    ]);

    $prediction = \App\Models\NBA\Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -8.5,
    ]);

    $alertService = app(\App\Services\AlertService::class);
    $alertsSent = $alertService->checkForValueOpportunities('nba');

    expect($alertsSent)->toBeGreaterThan(0);

    Notification::assertSentTo($user1, BettingValueAlert::class);
    Notification::assertSentTo($user2, BettingValueAlert::class);
});

test('alert service filters users based on template preferences', function () {
    Notification::fake();

    $template1 = NotificationTemplate::factory()->create([
        'name' => 'Betting Value Alert',
        'active' => true,
    ]);

    $template2 = NotificationTemplate::factory()->create([
        'name' => 'Other Template',
        'active' => true,
    ]);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    UserAlertPreference::factory()->create([
        'user_id' => $user1->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'enabled_template_ids' => [$template1->id],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    UserAlertPreference::factory()->create([
        'user_id' => $user2->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'enabled_template_ids' => [$template2->id],
        'minimum_edge' => 5.0,
        'time_window_start' => '00:00',
        'time_window_end' => '23:59',
        'digest_mode' => 'realtime',
    ]);

    $game = \App\Models\NBA\Game::create([
        'game_date' => now()->addDay(),
        'status' => 'scheduled',
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
        'home_team_id' => \App\Models\NBA\Team::factory()->create(['name' => 'Lakers'])->id,
        'away_team_id' => \App\Models\NBA\Team::factory()->create(['name' => 'Celtics'])->id,
    ]);

    $prediction = \App\Models\NBA\Prediction::create([
        'game_id' => $game->id,
        'confidence_score' => 85,
        'predicted_spread' => -8.5,
    ]);

    $alertService = app(\App\Services\AlertService::class);
    $alertsSent = $alertService->checkForValueOpportunities('nba');

    Notification::assertSentTo($user1, BettingValueAlert::class);
    Notification::assertNotSentTo($user2, BettingValueAlert::class);
});
