<?php

use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;

uses()->group('cbb', 'predictions');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['elo_rating' => 1550]);
    $this->awayTeam = Team::factory()->create(['elo_rating' => 1450]);
});

it('generates prediction for an upcoming game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeInstanceOf(Prediction::class)
        ->game_id->toBe($game->id);

    expect((float) $prediction->home_elo)->toBe(1550.0);
    expect((float) $prediction->away_elo)->toBe(1450.0);
    expect((float) $prediction->predicted_spread)->toBeGreaterThan(0);
    expect((float) $prediction->win_probability)->toBeGreaterThan(0.5);
});

it('calculates confidence from win probability', function () {
    // Big Elo gap → high win probability → high confidence
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1300]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    // Even teams → ~50% win probability → lower confidence
    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $game2 = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game1);
    $prediction2 = $action->execute($game2);

    // Mismatched game should have higher confidence
    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    // Confidence should be between 50 and 100
    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    // Confidence should equal max(wp, 1-wp) * 100
    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);

    $wp2 = (float) $prediction2->win_probability;
    $expectedConfidence2 = round(max($wp2, 1 - $wp2) * 100, 2);
    expect((float) $prediction2->confidence_score)->toBe($expectedConfidence2);
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeNull();
});
