<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Notifications\BettingValueAlert;
use App\Notifications\Channels\WhatsAppChannel;

test('betting value alert includes whatsapp channel when enabled in user preferences', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'notification_types' => ['whatsapp'],
        'phone_number' => '+1234567890',
    ]);

    $homeTeam = Team::factory()->create(['name' => 'Lakers']);
    $awayTeam = Team::factory()->create(['name' => 'Celtics']);
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => now()->addDay(),
        'game_time' => now()->addDay()->format('H:i:s'),
        'status' => 'scheduled',
        'odds_data' => [
            'home_team' => 'Lakers',
            'away_team' => 'Celtics',
            'bookmakers' => [],
        ],
    ]);

    $prediction = Prediction::query()->create([
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

    expect($notification->via($user->fresh()))->toContain(WhatsAppChannel::class);
});

test('betting value alert renders whatsapp message body with prediction url', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'notification_types' => ['whatsapp'],
        'phone_number' => '+1234567890',
    ]);

    $homeTeam = Team::factory()->create(['name' => 'Lakers']);
    $awayTeam = Team::factory()->create(['name' => 'Celtics']);
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => now()->addDay(),
        'game_time' => now()->addDay()->format('H:i:s'),
        'status' => 'scheduled',
        'odds_data' => [
            'home_team' => 'Lakers',
            'away_team' => 'Celtics',
            'bookmakers' => [],
        ],
    ]);

    $prediction = Prediction::query()->create([
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

    $message = $notification->toWhatsApp($user->fresh());

    expect($message)->toContain('Value Alert')
        ->and($message)->toContain('Celtics @ Lakers')
        ->and($message)->toContain('/nba/predictions/'.$game->id);
});
