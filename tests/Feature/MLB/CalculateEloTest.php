<?php

use App\Actions\MLB\CalculateElo;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

uses()->group('mlb', 'elo');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $this->awayTeam = Team::factory()->create(['elo_rating' => 1500]);
});

it('calculates elo ratings for a completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    expect($result['home_team_change'])->toBeGreaterThan(0)
        ->and($result['away_team_change'])->toBeLessThan(0)
        ->and($result['home_team_new_elo'])->toBeGreaterThan(1500)
        ->and($result['away_team_new_elo'])->toBeLessThan(1500);

    $this->homeTeam->refresh();
    $this->awayTeam->refresh();

    expect($this->homeTeam->elo_rating)->toBeGreaterThan(1500)
        ->and($this->awayTeam->elo_rating)->toBeLessThan(1500);
});

it('does not calculate elo for non-final games', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    expect($result['home_team_change'])->toBe(0)
        ->and($result['away_team_change'])->toBe(0);
});

it('applies home field advantage correctly', function () {
    // Create a stronger away team
    $this->awayTeam->update(['elo_rating' => 1600]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 4,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // Home team (lower Elo) winning should gain more points due to home advantage
    expect($result['home_team_change'])->toBeGreaterThan(10);
});

it('applies margin of victory multiplier for close games', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 3,
        'away_score' => 2,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    $closeGameChange = $result['home_team_change'];

    // Reset teams
    $this->homeTeam->update(['elo_rating' => 1500]);
    $this->awayTeam->update(['elo_rating' => 1500]);

    // Same scenario but with larger margin (blowout in MLB)
    $blowoutGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 12,
        'away_score' => 2,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $blowoutResult = $action->execute($blowoutGame);

    // Blowout should result in larger Elo change
    expect($blowoutResult['home_team_change'])->toBeGreaterThan($closeGameChange);
});

it('applies playoff multiplier correctly', function () {
    // Regular season game
    $regularGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $regularResult = $action->execute($regularGame);

    // Reset teams
    $this->homeTeam->update(['elo_rating' => 1500]);
    $this->awayTeam->update(['elo_rating' => 1500]);

    // Playoff game (season_type = 3)
    $playoffGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season_type' => 3,
    ]);

    $playoffResult = $action->execute($playoffGame);

    // Playoff game should result in larger Elo change
    expect($playoffResult['home_team_change'])->toBeGreaterThan($regularResult['home_team_change']);
});

it('saves elo history for both teams', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    $action = new CalculateElo;
    $action->execute($game);

    $homeHistory = EloRating::where('team_id', $this->homeTeam->id)->first();
    $awayHistory = EloRating::where('team_id', $this->awayTeam->id)->first();

    expect($homeHistory)->not->toBeNull()
        ->season->toBe(2025)
        ->date->format('Y-m-d')->toBe('2025-06-15')
        ->elo_rating->toBeGreaterThan(1500)
        ->elo_change->toBeGreaterThan(0);

    expect($awayHistory)->not->toBeNull()
        ->season->toBe(2025)
        ->elo_change->toBeLessThan(0);
});

it('handles upset victories correctly', function () {
    // Underdog at home
    $this->homeTeam->update(['elo_rating' => 1400]);
    $this->awayTeam->update(['elo_rating' => 1600]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // Underdog winning should gain more points (MLB has more conservative margins)
    expect($result['home_team_change'])->toBeGreaterThan(12);
    // Favorite losing should lose more points
    expect($result['away_team_change'])->toBeLessThan(-12);
});

it('updates team current elo and saves history simultaneously', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    $this->homeTeam->refresh();

    $homeHistory = EloRating::where('team_id', $this->homeTeam->id)->first();

    // Current rating on team should match the history
    expect($this->homeTeam->elo_rating)->toBe((int) $homeHistory->elo_rating);
});

it('skips games that already have elo history', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;

    // First calculation
    $result1 = $action->execute($game, skipIfExists: true);
    expect($result1['skipped'])->toBeFalse();
    expect($result1['home_team_change'])->toBeGreaterThan(0);

    $firstHomeElo = $this->homeTeam->refresh()->elo_rating;
    $historyCount = EloRating::count();

    // Second calculation with skipIfExists=true should skip
    $result2 = $action->execute($game, skipIfExists: true);
    expect($result2['skipped'])->toBeTrue();
    expect($result2['home_team_change'])->toBe(0);

    // Elo should not change
    expect($this->homeTeam->refresh()->elo_rating)->toBe($firstHomeElo);
    expect(EloRating::count())->toBe($historyCount);

    // Third calculation with skipIfExists=false should recalculate
    $result3 = $action->execute($game, skipIfExists: false);
    expect($result3['skipped'])->toBeFalse();
    expect($result3['home_team_change'])->not->toBe(0);

    // Elo should change and history should be added
    expect($this->homeTeam->refresh()->elo_rating)->not->toBe($firstHomeElo);
    expect(EloRating::count())->toBeGreaterThan($historyCount);
});

it('applies team regression to mean correctly', function () {
    $this->homeTeam->update(['elo_rating' => 1600]);

    $action = new CalculateElo;
    $regressedElo = $action->applyTeamRegression($this->homeTeam);

    // Should regress toward 1500
    expect($regressedElo)->toBeLessThan(1600)
        ->and($regressedElo)->toBeGreaterThan(1500);

    $this->homeTeam->refresh();
    expect($this->homeTeam->elo_rating)->toBe($regressedElo);
});
