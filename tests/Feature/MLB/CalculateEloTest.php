<?php

use App\Actions\MLB\CalculateElo;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
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
    ]);

    $blowoutResult = $action->execute($blowoutGame);

    // Blowout should result in larger Elo change
    expect($blowoutResult['home_team_change'])->toBeGreaterThan($closeGameChange);
});

it('applies playoff multiplier correctly', function () {
    // Regular season game
    $regularGame = Game::factory()->regularSeason()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateElo;
    $regularResult = $action->execute($regularGame);

    // Reset teams
    $this->homeTeam->update(['elo_rating' => 1500]);
    $this->awayTeam->update(['elo_rating' => 1500]);

    // Playoff game
    $playoffGame = Game::factory()->postseason()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
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

// --- Pitcher ELO Tests ---

it('calculates pitcher elo for starting pitchers', function () {
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1500,
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->awayTeam->id,
        'elo_rating' => 1500,
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    // Create pitching stats so getStartingPitcher finds them
    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 6.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 5.0,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // Winning pitcher gains, losing pitcher loses
    expect($result['home_pitcher_change'])->toBeGreaterThan(0)
        ->and($result['away_pitcher_change'])->toBeLessThan(0);

    // Pitcher Elo history should exist
    expect(PitcherEloRating::where('player_id', $homePitcher->id)->count())->toBe(1)
        ->and(PitcherEloRating::where('player_id', $awayPitcher->id)->count())->toBe(1);
});

it('skips pitcher elo when pitcher is missing', function () {
    // No pitching stats created — getStartingPitcher returns null
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    expect($result['home_pitcher_change'])->toBe(0)
        ->and($result['away_pitcher_change'])->toBe(0);

    expect(PitcherEloRating::count())->toBe(0);
});

it('uses separate k-factor for pitcher elo', function () {
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1500,
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->awayTeam->id,
        'elo_rating' => 1500,
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 6.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 5.0,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // pitcher_k_factor (15) < base_k_factor (20), so pitcher change < team change
    expect(abs($result['home_pitcher_change']))->toBeLessThan(abs($result['home_team_change']));
});

it('does not apply home field advantage to pitcher elo', function () {
    // Both pitchers at same Elo
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1500,
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->awayTeam->id,
        'elo_rating' => 1500,
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 6.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 5.0,
    ]);

    $action = new CalculateElo;
    $result = $action->execute($game);

    // With no HFA and equal ratings, changes should be symmetric (|home| == |away|)
    expect(abs($result['home_pitcher_change']))->toBe(abs($result['away_pitcher_change']));
});

it('applies pitcher regression to mean correctly', function () {
    $pitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1600,
    ]);

    $action = new CalculateElo;
    $regressedElo = $action->applyPitcherRegression($pitcher);

    // pitcher_regression_factor is 0.40 (stronger than team's 0.33)
    // regressedElo = 1600 + 0.40 * (1500 - 1600) = 1600 - 40 = 1560
    expect($regressedElo)->toBe(1560);

    $pitcher->refresh();
    expect($pitcher->elo_rating)->toBe(1560);
});

it('saves pitcher elo history with team_id', function () {
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1500,
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->awayTeam->id,
        'elo_rating' => 1500,
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 6.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 5.0,
    ]);

    $action = new CalculateElo;
    $action->execute($game);

    $homeHistory = PitcherEloRating::where('player_id', $homePitcher->id)->first();
    $awayHistory = PitcherEloRating::where('player_id', $awayPitcher->id)->first();

    expect($homeHistory->team_id)->toBe($this->homeTeam->id)
        ->and($awayHistory->team_id)->toBe($this->awayTeam->id);
});

it('tracks games_started count for pitchers per season', function () {
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->homeTeam->id,
        'elo_rating' => 1500,
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $this->awayTeam->id,
        'elo_rating' => 1500,
    ]);

    // Game 1
    $game1 = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-15',
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game1->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 6.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game1->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 5.0,
    ]);

    $action = new CalculateElo;
    $action->execute($game1);

    // Game 2 (same season)
    $game2 = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 3,
        'away_score' => 5,
        'status' => 'STATUS_FINAL',
        'season' => 2025,
        'game_date' => '2025-06-20',
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $homePitcher->id,
        'game_id' => $game2->id,
        'team_id' => $this->homeTeam->id,
        'innings_pitched' => 7.0,
    ]);
    PlayerStat::factory()->pitching()->create([
        'player_id' => $awayPitcher->id,
        'game_id' => $game2->id,
        'team_id' => $this->awayTeam->id,
        'innings_pitched' => 6.0,
    ]);

    $action->execute($game2);

    // games_started should increment per season
    $histories = PitcherEloRating::where('player_id', $homePitcher->id)
        ->orderBy('games_started')
        ->get();

    expect($histories)->toHaveCount(2)
        ->and($histories[0]->games_started)->toBe(1)
        ->and($histories[1]->games_started)->toBe(2);
});
