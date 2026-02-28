<?php

use App\Actions\MLB\UpdateLivePrediction;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;

uses()->group('mlb', 'live-predictions');

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
        'inning' => 0,
    ]);

    Prediction::query()->create(['game_id' => $game->id]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();
});

test('updates live prediction for in-progress game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'inning' => 5,
        'home_score' => 3,
        'away_score' => 2,
    ]);
    $game->inning_state = 'top';

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.0,
        'predicted_total' => 8.5,
        'win_probability' => 0.58,
        'confidence_score' => 0.71,
    ]);

    $result = $this->action->execute($game);

    expect($result)->not->toBeNull()
        ->and($result['live_predicted_spread'])->toBeFloat()
        ->and($result['live_win_probability'])->toBeFloat()
        ->and($result['live_predicted_total'])->toBeFloat()
        ->and($result['live_outs_remaining'])->toBeInt();

    $prediction->refresh();

    expect($prediction->live_predicted_spread)->not->toBeNull()
        ->and($prediction->live_win_probability)->not->toBeNull()
        ->and($prediction->live_predicted_total)->not->toBeNull()
        ->and($prediction->live_outs_remaining)->not->toBeNull()
        ->and($prediction->live_updated_at)->not->toBeNull();
});

test('clears stale live data when game is no longer in progress', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'inning' => 9,
        'home_score' => 5,
        'away_score' => 3,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'live_predicted_spread' => 1.5,
        'live_win_probability' => 0.940,
        'live_predicted_total' => 8.0,
        'live_outs_remaining' => 3,
        'live_updated_at' => now(),
    ]);

    $result = $this->action->execute($game->fresh());

    expect($result)->toBeNull();

    $prediction->refresh();

    expect($prediction->live_predicted_spread)->toBeNull()
        ->and($prediction->live_win_probability)->toBeNull()
        ->and($prediction->live_predicted_total)->toBeNull()
        ->and($prediction->live_outs_remaining)->toBeNull()
        ->and($prediction->live_updated_at)->toBeNull();
});
