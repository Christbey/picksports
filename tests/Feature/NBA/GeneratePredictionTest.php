<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

uses()->group('nba', 'predictions');

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
    expect((float) $prediction->predicted_spread)->toBeGreaterThan(0); // Home team favored
    expect((float) $prediction->win_probability)->toBeGreaterThan(0.5); // Home team more likely to win
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 100,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeNull();
});

it('uses team metrics when available', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 115.0,
        'defensive_efficiency' => 105.0,
        'net_rating' => 10.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -4.0,
        'tempo' => 98.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(115.0);
    expect((float) $prediction->home_def_eff)->toBe(105.0);
    expect((float) $prediction->away_off_eff)->toBe(108.0);
    expect((float) $prediction->away_def_eff)->toBe(112.0);
    expect((float) $prediction->predicted_total)->toBeGreaterThan(200); // Should be realistic NBA total
});

it('uses default metrics when team metrics unavailable', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(110.0); // League average
    expect((float) $prediction->home_def_eff)->toBe(110.0);
    expect((float) $prediction->away_off_eff)->toBe(110.0);
    expect((float) $prediction->away_def_eff)->toBe(110.0);
});

it('favors home team with home court advantage', function () {
    // Create evenly matched teams
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    // Even teams, but home should be favored due to home court advantage
    expect($prediction)->not->toBeNull()
        ->predicted_spread->toBeGreaterThan(0) // Positive = home favored
        ->win_probability->toBeGreaterThan(0.5); // Home team more likely to win
});

it('updates existing prediction instead of creating duplicate', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    // First prediction
    $prediction1 = $action->execute($game);
    expect(Prediction::count())->toBe(1);
    $firstHomeElo = (float) $prediction1->home_elo;

    // Update team Elo
    $this->homeTeam->update(['elo_rating' => 1600]);
    $game->refresh(); // Refresh to get updated team relationship

    // Second prediction should update, not create new
    $prediction2 = $action->execute($game);

    expect(Prediction::count())->toBe(1);
    expect($prediction2->id)->toBe($prediction1->id);
    expect((float) $prediction2->home_elo)->toBe(1600.0);
    expect((float) $prediction2->home_elo)->not->toBe($firstHomeElo);
});

it('calculates higher win probability for bigger spread', function () {
    // Home team much better
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1400]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    // Evenly matched teams
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

    // Bigger Elo diff should have bigger spread and higher win probability
    expect($prediction1->predicted_spread)->toBeGreaterThan($prediction2->predicted_spread)
        ->and($prediction1->win_probability)->toBeGreaterThan($prediction2->win_probability);
});

it('calculates confidence based on win probability', function () {
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

    // Mismatched game should have higher confidence than even matchup
    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    // Confidence should be between 50 and 100
    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    // Confidence should equal max(wp, 1-wp) * 100
    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);
});
