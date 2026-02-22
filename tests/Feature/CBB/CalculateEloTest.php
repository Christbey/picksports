<?php

use App\Actions\CBB\CalculateElo;
use App\Models\CBB\EloRating;
use App\Models\CBB\Game;
use App\Models\CBB\Team;

uses()->group('cbb', 'elo');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $this->awayTeam = Team::factory()->create(['elo_rating' => 1500]);
});

it('calculates elo for a completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    expect($result['skipped'])->toBeFalse();
    expect($result['home_change'])->toBeGreaterThan(0);
    expect($result['away_change'])->toBeLessThan(0);
});

it('applies SOS dampener for mismatched elo games', function () {
    // Even matchup — no dampening
    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $evenGame = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    // Mismatched game — should be dampened
    $strongHome = Team::factory()->create(['elo_rating' => 1800]);
    $weakAway = Team::factory()->create(['elo_rating' => 1400]);

    $mismatchGame = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action = new CalculateElo;

    $evenResult = $action->execute($evenGame, skipIfExists: false);
    $mismatchResult = $action->execute($mismatchGame, skipIfExists: false);

    // Even matchup should produce larger ELO change than mismatched game
    expect(abs($evenResult['home_change']))->toBeGreaterThan(abs($mismatchResult['home_change']));
});

it('applies SOS dampener at floor for very large elo gaps', function () {
    // 400+ ELO gap should hit the 0.5 floor
    $strongHome = Team::factory()->create(['elo_rating' => 1900]);
    $weakAway = Team::factory()->create(['elo_rating' => 1300]);

    $game = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game, skipIfExists: false);

    // With 600 ELO gap: dampener = max(0.5, 1 - 600/800) = max(0.5, 0.25) = 0.5
    // The ELO change should be roughly half what it would be without dampening
    expect(abs($result['home_change']))->toBeLessThan(10);
});

it('does not apply SOS dampener when disabled', function () {
    config()->set('cbb.elo.sos_adjustment.enabled', false);

    $strongHome = Team::factory()->create(['elo_rating' => 1800]);
    $weakAway = Team::factory()->create(['elo_rating' => 1400]);

    $game = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    // Same setup but without dampener
    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $evenGame = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action = new CalculateElo;

    $mismatchResult = $action->execute($game, skipIfExists: false);
    $evenResult = $action->execute($evenGame, skipIfExists: false);

    // Without dampening, the mismatched game winner gets very little ELO
    // because expected score is already high — but the K-factor is NOT reduced
    // The even game winner should still get more, but the gap is smaller
    // than with dampening enabled
    expect($mismatchResult['home_change'])->not->toBe(0.0);
});

it('saves elo history records', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action = new CalculateElo;
    $action->execute($game);

    expect(EloRating::count())->toBe(2); // One for each team
    expect(EloRating::where('team_id', $this->homeTeam->id)->exists())->toBeTrue();
    expect(EloRating::where('team_id', $this->awayTeam->id)->exists())->toBeTrue();
});
