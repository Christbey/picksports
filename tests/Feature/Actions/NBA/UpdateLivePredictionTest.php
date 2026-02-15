<?php

use App\Actions\NBA\UpdateLivePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;

uses()->group('nba', 'live-predictions');

beforeEach(function () {
    $this->refreshDatabase();
    $this->action = new UpdateLivePrediction;
    $this->homeTeam = Team::factory()->create();
    $this->awayTeam = Team::factory()->create();
});

test('returns null for scheduled game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'period' => 0,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();
});

test('returns null for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'period' => 4,
        'home_score' => 110,
        'away_score' => 105,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();
});

test('returns null for game without prediction', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '5:00',
        'home_score' => 55,
        'away_score' => 50,
    ]);

    $result = $this->action->execute($game);

    expect($result)->toBeNull();
});

test('updates live prediction for in-progress game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '6:00',
        'home_score' => 55,
        'away_score' => 50,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -5.0,
        'predicted_total' => 220.0,
        'win_probability' => 0.65,
    ]);

    $result = $this->action->execute($game->fresh());

    expect($result)->not->toBeNull()
        ->and($result['live_predicted_spread'])->toBeFloat()
        ->and($result['live_win_probability'])->toBeFloat()
        ->and($result['live_predicted_total'])->toBeFloat()
        ->and($result['live_seconds_remaining'])->toBeInt();

    $prediction->refresh();

    expect($prediction->live_predicted_spread)->not->toBeNull()
        ->and($prediction->live_win_probability)->not->toBeNull()
        ->and($prediction->live_predicted_total)->not->toBeNull()
        ->and($prediction->live_seconds_remaining)->not->toBeNull()
        ->and($prediction->live_updated_at)->not->toBeNull();
});

test('calculates seconds remaining correctly for quarter 1', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '7:30',
        'home_score' => 20,
        'away_score' => 18,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Q1 7:30 = 3 quarters left (2160 sec) + 7:30 (450 sec) = 2610 seconds
    expect($result['live_seconds_remaining'])->toBe(2610);
});

test('calculates seconds remaining correctly for quarter 3', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '5:00',
        'home_score' => 75,
        'away_score' => 70,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Q3 5:00 = 1 quarter left (720 sec) + 5:00 (300 sec) = 1020 seconds
    expect($result['live_seconds_remaining'])->toBe(1020);
});

test('calculates seconds remaining correctly for quarter 4', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '2:00',
        'home_score' => 102,
        'away_score' => 98,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Q4 2:00 = 0 quarters left + 2:00 (120 sec) = 120 seconds
    expect($result['live_seconds_remaining'])->toBe(120);
});

test('handles overtime correctly', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 5, // OT1
        'game_clock' => '3:00',
        'home_score' => 110,
        'away_score' => 110,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // OT capped at 5 min (300 sec), 3:00 remaining = 180 sec
    expect($result['live_seconds_remaining'])->toBe(180);
});

test('handles halftime status', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_HALFTIME',
        'period' => 2,
        'game_clock' => '0:00',
        'home_score' => 55,
        'away_score' => 50,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->not->toBeNull()
        ->and($result['live_seconds_remaining'])->toBe(1440); // 2 quarters * 720 sec
});

test('calculates live win probability with home team leading', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '5:00',
        'home_score' => 85,
        'away_score' => 75,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.55,
    ]);

    $result = $this->action->execute($game->fresh());

    // Home team up 10 in Q3 should have high win probability
    expect($result['live_win_probability'])->toBeGreaterThan(0.7);
});

test('calculates live win probability with away team leading', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '3:00',
        'home_score' => 95,
        'away_score' => 105,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.60,
    ]);

    $result = $this->action->execute($game->fresh());

    // Away team up 10 late in Q4 - home should have low win probability
    expect($result['live_win_probability'])->toBeLessThan(0.3);
});

test('calculates live spread reflecting current margin', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '1:00',
        'home_score' => 108,
        'away_score' => 100,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -3.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // Late game margin of 8, should be close to final spread
    expect($result['live_predicted_spread'])->toBeGreaterThan(6.0)
        ->and($result['live_predicted_spread'])->toBeLessThan(9.0);
});

test('calculates live total based on scoring pace', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '6:00',
        'home_score' => 60,
        'away_score' => 55,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_total' => 220.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // After ~1.5 quarters (1080 sec elapsed), 115 pts scored
    // Pace suggests ~230+ total
    expect($result['live_predicted_total'])->toBeGreaterThan(115);
});

test('clears live prediction', function () {
    $prediction = Prediction::factory()->create([
        'game_id' => Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $this->awayTeam->id,
        ])->id,
        'live_predicted_spread' => 5.5,
        'live_win_probability' => 0.750,
        'live_predicted_total' => 215.0,
        'live_seconds_remaining' => 600,
        'live_updated_at' => now(),
    ]);

    $this->action->clearLivePrediction($prediction);

    $prediction->refresh();

    expect($prediction->live_predicted_spread)->toBeNull()
        ->and($prediction->live_win_probability)->toBeNull()
        ->and($prediction->live_predicted_total)->toBeNull()
        ->and($prediction->live_seconds_remaining)->toBeNull()
        ->and($prediction->live_updated_at)->toBeNull();
});
