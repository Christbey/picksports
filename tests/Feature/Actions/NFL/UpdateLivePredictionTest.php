<?php

use App\Actions\NFL\UpdateLivePrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

uses()->group('nfl', 'live-predictions');

beforeEach(function () {
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
        'home_score' => 28,
        'away_score' => 21,
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
        'period' => 4,
        'home_score' => 28,
        'away_score' => 21,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'live_predicted_spread' => 6.5,
        'live_win_probability' => 0.910,
        'live_predicted_total' => 49.5,
        'live_seconds_remaining' => 180,
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

test('returns null for game without prediction', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '10:00',
        'home_score' => 14,
        'away_score' => 10,
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
        'game_clock' => '8:00',
        'home_score' => 14,
        'away_score' => 10,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -6.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.68,
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
        'game_clock' => '10:00',
        'home_score' => 7,
        'away_score' => 3,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Q1 10:00 = 3 quarters left (2700 sec) + 10:00 (600 sec) = 3300 seconds
    expect($result['live_seconds_remaining'])->toBe(3300);
});

test('calculates seconds remaining correctly for quarter 3', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '5:00',
        'home_score' => 21,
        'away_score' => 17,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // Q3 5:00 = 1 quarter left (900 sec) + 5:00 (300 sec) = 1200 seconds
    expect($result['live_seconds_remaining'])->toBe(1200);
});

test('calculates seconds remaining correctly for quarter 4', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '2:00',
        'home_score' => 24,
        'away_score' => 21,
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
        'game_clock' => '5:00',
        'home_score' => 28,
        'away_score' => 28,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    // OT capped at 10 min (600 sec), 5:00 remaining = 300 sec
    expect($result['live_seconds_remaining'])->toBe(300);
});

test('uses regulation elapsed baseline at overtime start', function () {
    $reflection = new ReflectionClass($this->action);
    $method = $reflection->getMethod('calculateActualSecondsElapsed');
    $method->setAccessible(true);

    $elapsedAtOtStart = $method->invoke($this->action, 5, '10:00');

    // At OT start, elapsed time should be full regulation (3600 seconds), not less.
    expect($elapsedAtOtStart)->toBe(3600);
});

test('handles halftime status', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_HALFTIME',
        'period' => 2,
        'game_clock' => '0:00',
        'home_score' => 14,
        'away_score' => 10,
    ]);

    Prediction::factory()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->not->toBeNull()
        ->and($result['live_seconds_remaining'])->toBe(1800); // 2 quarters * 900 sec
});

test('calculates live win probability with home team leading', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '5:00',
        'home_score' => 24,
        'away_score' => 14,
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
        'home_score' => 17,
        'away_score' => 24,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.60,
    ]);

    $result = $this->action->execute($game->fresh());

    // Away team up 7 late in Q4 - home should have low win probability
    expect($result['live_win_probability'])->toBeLessThan(0.4);
});

test('calculates live spread reflecting current margin', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '1:00',
        'home_score' => 28,
        'away_score' => 21,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -3.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // Late game margin of 7, should be close to final spread
    expect($result['live_predicted_spread'])->toBeGreaterThan(5.0)
        ->and($result['live_predicted_spread'])->toBeLessThan(8.0);
});

test('calculates live total based on scoring pace', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '10:00',
        'home_score' => 17,
        'away_score' => 14,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_total' => 45.0,
    ]);

    $result = $this->action->execute($game->fresh());

    // After ~1.25 quarters (1350 sec elapsed), 31 pts scored
    // Pace suggests higher final total
    expect($result['live_predicted_total'])->toBeGreaterThan(31);
});

test('clears live prediction', function () {
    $prediction = Prediction::factory()->create([
        'game_id' => Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $this->awayTeam->id,
        ])->id,
        'live_predicted_spread' => 7.0,
        'live_win_probability' => 0.820,
        'live_predicted_total' => 52.0,
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
