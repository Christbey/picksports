<?php

use App\Actions\CBB\UpdateLivePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;

uses()->group('cbb', 'live-predictions');

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
        'period' => 2,
        'home_score' => 75,
        'away_score' => 68,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();
});

test('clears stale live data when game is no longer in progress', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'period' => 2,
        'home_score' => 75,
        'away_score' => 68,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'live_predicted_spread' => 4.5,
        'live_win_probability' => 0.750,
        'live_predicted_total' => 148.0,
        'live_seconds_remaining' => 120,
        'live_updated_at' => now(),
    ]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();

    $prediction->refresh();

    expect($prediction->live_predicted_spread)->toBeNull()
        ->and($prediction->live_win_probability)->toBeNull()
        ->and($prediction->live_predicted_total)->toBeNull()
        ->and($prediction->live_seconds_remaining)->toBeNull()
        ->and($prediction->live_updated_at)->toBeNull();
});

test('does not clear live data for scheduled game without prior live data', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'period' => 0,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'live_seconds_remaining' => null,
    ]);

    $this->action->execute($game->fresh());

    $prediction->refresh();

    // Should not have attempted any update since live_seconds_remaining was already null
    expect($prediction->live_seconds_remaining)->toBeNull();
});

test('returns null for game without prediction', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '10:00',
        'home_score' => 30,
        'away_score' => 28,
    ]);

    $result = $this->action->execute($game);

    expect($result)->toBeNull();
});

test('updates live prediction for in-progress game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '10:00',
        'home_score' => 30,
        'away_score' => 28,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -4.0,
        'predicted_total' => 140.0,
        'win_probability' => 0.62,
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

test('handles suspended game as in progress', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SUSPENDED',
        'period' => 2,
        'game_clock' => '8:00',
        'home_score' => 45,
        'away_score' => 42,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -3.0,
        'predicted_total' => 140.0,
        'win_probability' => 0.55,
    ]);

    $result = $this->action->execute($game->fresh());

    expect($result)->not->toBeNull()
        ->and($result['live_predicted_spread'])->toBeFloat()
        ->and($result['live_win_probability'])->toBeFloat()
        ->and($result['live_seconds_remaining'])->toBe(480);
});

test('calculates seconds remaining correctly for first half', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1, // First half
        'game_clock' => '15:00',
        'home_score' => 15,
        'away_score' => 12,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // First half 15:00 = 1 half left (1200 sec) + 15:00 (900 sec) = 2100 seconds
    expect($result['live_seconds_remaining'])->toBe(2100);
});

test('calculates seconds remaining correctly for second half', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2, // Second half
        'game_clock' => '10:00',
        'home_score' => 55,
        'away_score' => 50,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Second half 10:00 = 0 halves left + 10:00 (600 sec) = 600 seconds
    expect($result['live_seconds_remaining'])->toBe(600);
});

test('calculates seconds remaining correctly late in second half', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '2:00',
        'home_score' => 70,
        'away_score' => 65,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Second half 2:00 = 120 seconds
    expect($result['live_seconds_remaining'])->toBe(120);
});

test('handles overtime correctly', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3, // OT1
        'game_clock' => '3:00',
        'home_score' => 75,
        'away_score' => 75,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // OT capped at 5 min (300 sec), 3:00 remaining = 180 sec
    expect($result['live_seconds_remaining'])->toBe(180);
});

test('handles double overtime correctly', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4, // OT2
        'game_clock' => '4:00',
        'home_score' => 85,
        'away_score' => 83,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_total' => 145.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // 2OT with 4:00 left = 240 sec remaining
    expect($result['live_seconds_remaining'])->toBe(240)
        ->and($result['live_predicted_total'])->toBeGreaterThan(168);
});

test('handles halftime status', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_HALFTIME',
        'period' => 1,
        'game_clock' => '0:00',
        'home_score' => 35,
        'away_score' => 32,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->not->toBeNull()
        ->and($result['live_seconds_remaining'])->toBe(1200); // 1 half * 1200 sec
});

test('calculates live win probability with home team leading', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '5:00',
        'home_score' => 68,
        'away_score' => 58,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.55,
    ]);

    $result = $this->action->execute($game->fresh());

    // Home team up 10 late in 2nd half should have high win probability
    expect($result['live_win_probability'])->toBeGreaterThan(0.7);
});

test('calculates live win probability with away team leading', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '3:00',
        'home_score' => 60,
        'away_score' => 70,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.60,
    ]);

    $result = $this->action->execute($game->fresh());

    // Away team up 10 very late in game - home should have low win probability
    expect($result['live_win_probability'])->toBeLessThan(0.3);
});

test('calculates live spread reflecting current margin', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '1:00',
        'home_score' => 72,
        'away_score' => 66,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -3.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // Late game margin of 6, should be close to final spread
    expect($result['live_predicted_spread'])->toBeGreaterThan(4.0)
        ->and($result['live_predicted_spread'])->toBeLessThan(7.0);
});

test('live spread incorporates pre-game prediction at halftime', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_HALFTIME',
        'period' => 1,
        'game_clock' => '0:00',
        'home_score' => 40,
        'away_score' => 30,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -5.0, // Pre-game: away favored by 5
    ]);

    $result = $this->action->execute($game->fresh());

    // At halftime up 10 with pre-game spread of -5, the live spread should
    // incorporate some regression — not just equal the current margin of 10
    expect($result['live_predicted_spread'])->toBeGreaterThan(5.0)
        ->and($result['live_predicted_spread'])->toBeLessThan(10.0);
});

test('calculates live total based on scoring pace', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '10:00',
        'home_score' => 35,
        'away_score' => 30,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_total' => 140.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // After 10 min (600 sec elapsed), 65 pts scored
    // Pace suggests higher than current 65
    expect($result['live_predicted_total'])->toBeGreaterThan(65);
});

test('overtime total uses dynamic upper bound', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3, // OT1
        'game_clock' => '2:00',
        'home_score' => 100,
        'away_score' => 100,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_total' => 180.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // In OT with 200 points already, total should exceed 200
    // Upper bound for OT1 = 220 + 25 = 245
    expect($result['live_predicted_total'])->toBeGreaterThan(200);
});

test('clears live prediction', function () {
    $prediction = Prediction::factory()->create([
        'game_id' => Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $this->awayTeam->id,
        ])->id,
        'live_predicted_spread' => 4.5,
        'live_win_probability' => 0.680,
        'live_predicted_total' => 145.0,
        'live_seconds_remaining' => 450,
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
