<?php

use App\Actions\WCBB\CalculateElo;
use App\Models\WCBB\EloRating;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;

uses()->group('wcbb', 'elo');

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

it('persists elo rating on the team model', function () {
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

    $this->homeTeam->refresh();
    $this->awayTeam->refresh();

    expect($this->homeTeam->elo_rating)->toBe($result['home_new_elo']);
    expect($this->awayTeam->elo_rating)->toBe($result['away_new_elo']);
    expect($this->homeTeam->elo_rating)->toBeGreaterThan(1500);
    expect($this->awayTeam->elo_rating)->toBeLessThan(1500);
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

    expect(EloRating::count())->toBe(2);
    expect(EloRating::where('team_id', $this->homeTeam->id)->exists())->toBeTrue();
    expect(EloRating::where('team_id', $this->awayTeam->id)->exists())->toBeTrue();
});

it('cascades elo across multiple games', function () {
    // First game: home wins
    $game1 = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
        'season' => 2026,
        'game_date' => now()->subDay()->toDateString(),
    ]);

    $action = new CalculateElo;
    $action->execute($game1);

    $this->homeTeam->refresh();
    $this->awayTeam->refresh();
    $eloAfterGame1Home = $this->homeTeam->elo_rating;
    $eloAfterGame1Away = $this->awayTeam->elo_rating;

    // Second game: away wins (revenge)
    $game2 = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 65,
        'away_score' => 75,
        'season' => 2026,
        'game_date' => now()->toDateString(),
    ]);

    $action->execute($game2);

    $this->homeTeam->refresh();
    $this->awayTeam->refresh();

    // After game 2, home team should have lost some rating from game 1 gains
    expect($this->homeTeam->elo_rating)->toBeLessThan($eloAfterGame1Home);
    expect($this->awayTeam->elo_rating)->toBeGreaterThan($eloAfterGame1Away);

    expect(EloRating::count())->toBe(4); // 2 per game
});
