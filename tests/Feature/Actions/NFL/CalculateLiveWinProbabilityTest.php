<?php

use App\Actions\NFL\CalculateLiveWinProbability;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

beforeEach(function () {
    $this->calculator = app(CalculateLiveWinProbability::class);
    $this->homeTeam = Team::factory()->create();
    $this->awayTeam = Team::factory()->create();
});

test('returns null for non-live games', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'period' => 0,
    ]);

    $result = $this->calculator->execute($game);

    expect($result)->toBeNull();
});

test('returns null for completed games', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'period' => 4,
        'home_score' => 24,
        'away_score' => 17,
    ]);

    $result = $this->calculator->execute($game);

    expect($result)->toBeNull();
});

test('calculates live probability for in-progress game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '5:00',
        'home_score' => 14,
        'away_score' => 7,
    ]);

    $result = $this->calculator->execute($game);

    expect($result)->not->toBeNull()
        ->and($result['is_live'])->toBeTrue()
        ->and($result['margin'])->toBe(7)
        ->and($result['home_win_probability'])->toBeGreaterThan(0.5)
        ->and($result['away_win_probability'])->toBeLessThan(0.5)
        ->and($result['home_win_probability'] + $result['away_win_probability'])->toBeGreaterThanOrEqual(0.99);
});

test('home team leading increases probability', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '10:00',
        'home_score' => 21,
        'away_score' => 7,
    ]);

    $result = $this->calculator->execute($game);

    expect($result['home_win_probability'])->toBeGreaterThan(0.6);
});

test('away team leading decreases home probability', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '10:00',
        'home_score' => 7,
        'away_score' => 21,
    ]);

    $result = $this->calculator->execute($game);

    expect($result['home_win_probability'])->toBeLessThan(0.4)
        ->and($result['away_win_probability'])->toBeGreaterThan(0.6);
});

test('late game lead increases probability more', function () {
    $gameEarly = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '10:00',
        'home_score' => 7,
        'away_score' => 0,
    ]);

    $gameLate = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'game_clock' => '2:00',
        'home_score' => 7,
        'away_score' => 0,
    ]);

    $earlyResult = $this->calculator->execute($gameEarly);
    $lateResult = $this->calculator->execute($gameLate);

    expect($lateResult['home_win_probability'])->toBeGreaterThan($earlyResult['home_win_probability']);
});

test('incorporates pre-game probability', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 1,
        'game_clock' => '15:00',
        'home_score' => 0,
        'away_score' => 0,
    ]);

    Prediction::create([
        'game_id' => $game->id,
        'win_probability' => 0.7,
    ]);

    $game->load('prediction');
    $result = $this->calculator->execute($game);

    expect($result['home_win_probability'])->toBeGreaterThan(0.5);
});

test('calculates seconds remaining correctly', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '7:30',
        'home_score' => 14,
        'away_score' => 14,
    ]);

    $result = $this->calculator->execute($game);

    // Q3 7:30 = 1 quarter left (900 sec) + 7:30 (450 sec) = 1350 seconds
    expect($result['seconds_remaining'])->toBe(1350);
});

test('handles overtime periods', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 5, // OT1
        'game_clock' => '5:00',
        'home_score' => 24,
        'away_score' => 24,
    ]);

    $result = $this->calculator->execute($game);

    expect($result)->not->toBeNull()
        ->and($result['seconds_remaining'])->toBe(300);
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

    $result = $this->calculator->execute($game);

    expect($result)->not->toBeNull()
        ->and($result['is_live'])->toBeTrue()
        ->and($result['seconds_remaining'])->toBe(1800);
});
