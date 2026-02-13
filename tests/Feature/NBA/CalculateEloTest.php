<?php

use App\Actions\NBA\CalculateElo;
use App\Models\NBA\EloRating;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

uses()->group('nba', 'elo');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $this->awayTeam = Team::factory()->create(['elo_rating' => 1500]);
});

it('calculates elo ratings for a completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 110,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    expect($result['home_change'])->toBeGreaterThan(0)
        ->and($result['away_change'])->toBeLessThan(0)
        ->and($result['home_new_elo'])->toBeGreaterThan(1500)
        ->and($result['away_new_elo'])->toBeLessThan(1500);

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

    expect($result['home_change'])->toBe(0)
        ->and($result['away_change'])->toBe(0);
});

it('applies home court advantage correctly', function () {
    // Create a stronger away team
    $this->awayTeam->update(['elo_rating' => 1600]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 105,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // Home team (lower Elo) winning should gain more points due to home advantage
    expect($result['home_change'])->toBeGreaterThan(10);
});

it('applies margin of victory multiplier for close games', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 102,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    $closeGameChange = $result['home_change'];

    // Reset teams
    $this->homeTeam->update(['elo_rating' => 1500]);
    $this->awayTeam->update(['elo_rating' => 1500]);

    // Same scenario but with larger margin
    $blowoutGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 125,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $blowoutResult = $action->execute($blowoutGame);

    // Blowout should result in larger Elo change
    expect($blowoutResult['home_change'])->toBeGreaterThan($closeGameChange);
});

it('applies playoff multiplier correctly', function () {
    // Regular season game
    $regularGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 110,
        'away_score' => 100,
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
        'home_score' => 110,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 3,
    ]);

    $playoffResult = $action->execute($playoffGame);

    // Playoff game should result in larger Elo change
    expect($playoffResult['home_change'])->toBeGreaterThan($regularResult['home_change']);
});

it('saves elo history for both teams', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 110,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => '2026-01-30',
    ]);

    $action = new CalculateElo;
    $action->execute($game);

    $homeHistory = EloRating::where('team_id', $this->homeTeam->id)->first();
    $awayHistory = EloRating::where('team_id', $this->awayTeam->id)->first();

    expect($homeHistory)->not->toBeNull()
        ->season->toBe(2026)
        ->date->format('Y-m-d')->toBe('2026-01-30')
        ->elo_rating->toBeGreaterThan(1500)
        ->elo_change->toBeGreaterThan(0);

    expect($awayHistory)->not->toBeNull()
        ->season->toBe(2026)
        ->elo_change->toBeLessThan(0);
});

it('handles upset victories correctly', function () {
    // Underdog at home
    $this->homeTeam->update(['elo_rating' => 1400]);
    $this->awayTeam->update(['elo_rating' => 1600]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 110,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // Underdog winning should gain more points
    expect($result['home_change'])->toBeGreaterThan(15);
    // Favorite losing should lose more points
    expect($result['away_change'])->toBeLessThan(-15);
});

it('updates team current elo and saves history simultaneously', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 110,
        'away_score' => 100,
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
        'home_score' => 110,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
        'season_type' => 2,
    ]);

    $action = new CalculateElo;

    // First calculation
    $result1 = $action->execute($game, skipIfExists: true);
    expect($result1['skipped'])->toBeFalse();
    expect($result1['home_change'])->toBeGreaterThan(0);

    $firstHomeElo = $this->homeTeam->refresh()->elo_rating;
    $historyCount = EloRating::count();

    // Second calculation with skipIfExists=true should skip
    $result2 = $action->execute($game, skipIfExists: true);
    expect($result2['skipped'])->toBeTrue();
    expect($result2['home_change'])->toBe(0);

    // Elo should not change
    expect($this->homeTeam->refresh()->elo_rating)->toBe($firstHomeElo);
    expect(EloRating::count())->toBe($historyCount);

    // Third calculation with skipIfExists=false should recalculate
    $result3 = $action->execute($game, skipIfExists: false);
    expect($result3['skipped'])->toBeFalse();
    expect($result3['home_change'])->not->toBe(0);

    // Elo should change and history should be added
    expect($this->homeTeam->refresh()->elo_rating)->not->toBe($firstHomeElo);
    expect(EloRating::count())->toBeGreaterThan($historyCount);
});
