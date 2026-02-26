<?php

use App\Actions\GradePlayerProps;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\PlayerProp;

test('grades player props correctly for completed games', function () {
    // Create teams
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    // Create a completed game
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 105,
        'season' => 2026,
    ]);

    // Create a player
    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'first_name' => 'LeBron',
        'last_name' => 'James',
    ]);

    // Create player stats for the game (player scored 28 points)
    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'team_id' => $homeTeam->id,
        'points' => 28,
        'rebounds_total' => 8,
        'assists' => 12,
        'three_point_made' => 3,
        'steals' => 2,
        'blocks' => 1,
    ]);

    // Create player props for different markets
    $pointsProp = PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'LeBron James',
        'market' => 'player_points',
        'line' => 25.5, // Line was 25.5, actual was 28 (OVER hit)
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $reboundsProp = PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'LeBron James',
        'market' => 'player_rebounds',
        'line' => 9.5, // Line was 9.5, actual was 8 (UNDER hit)
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $assistsProp = PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'LeBron James',
        'market' => 'player_assists',
        'line' => 12.5, // Line was 12.5, actual was 12 (UNDER hit)
        'over_price' => -110,
        'under_price' => -110,
    ]);

    // Grade the props
    $grader = new GradePlayerProps;
    $results = $grader->execute('basketball_nba', 2026);

    // Assert grading results
    expect($results['graded'])->toBe(3);
    expect($results['hit_rate'])->toBe(33.3); // 1 out of 3 hit over

    // Refresh props and check individual results
    $pointsProp->refresh();
    expect($pointsProp->actual_value)->toBe(28.0);
    expect($pointsProp->hit_over)->toBeTrue();
    expect($pointsProp->error)->toBe(2.5); // |28 - 25.5| = 2.5
    expect($pointsProp->graded_at)->not->toBeNull();

    $reboundsProp->refresh();
    expect($reboundsProp->actual_value)->toBe(8.0);
    expect($reboundsProp->hit_over)->toBeFalse();
    expect($reboundsProp->error)->toBe(1.5); // |8 - 9.5| = 1.5
    expect($reboundsProp->graded_at)->not->toBeNull();

    $assistsProp->refresh();
    expect($assistsProp->actual_value)->toBe(12.0);
    expect($assistsProp->hit_over)->toBeFalse();
    expect($assistsProp->error)->toBe(0.5); // |12 - 12.5| = 0.5
    expect($assistsProp->graded_at)->not->toBeNull();
});

test('handles combined stat markets correctly', function () {
    // Create teams and game
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 105,
        'season' => 2026,
    ]);

    // Create player and stats
    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'first_name' => 'Stephen',
        'last_name' => 'Curry',
    ]);

    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'team_id' => $homeTeam->id,
        'points' => 30,
        'rebounds_total' => 5,
        'assists' => 10,
        'blocks' => 0,
        'steals' => 3,
    ]);

    // Test points + rebounds + assists (30 + 5 + 10 = 45)
    $praProp = PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'Stephen Curry',
        'market' => 'player_points_rebounds_assists',
        'line' => 42.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    // Test blocks + steals (0 + 3 = 3)
    $bsProp = PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'Stephen Curry',
        'market' => 'player_blocks_steals',
        'line' => 2.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    // Grade
    $grader = new GradePlayerProps;
    $results = $grader->execute('basketball_nba', 2026);

    // Check results
    $praProp->refresh();
    expect($praProp->actual_value)->toBe(45.0);
    expect($praProp->hit_over)->toBeTrue();

    $bsProp->refresh();
    expect($bsProp->actual_value)->toBe(3.0);
    expect($bsProp->hit_over)->toBeTrue();
});

test('skips props without matching player stats', function () {
    // Create game
    $game = Game::factory()->create([
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 105,
        'season' => 2026,
    ]);

    // Create prop without corresponding player stat
    PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'sport' => 'basketball_nba',
        'player_name' => 'Non Existent Player',
        'market' => 'player_points',
        'line' => 25.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $grader = new GradePlayerProps;
    $results = $grader->execute('basketball_nba', 2026);

    // Should skip props without matching stats
    expect($results['graded'])->toBe(0);
});

test('does not regrade already graded props', function () {
    // Create game, player, and stats
    $game = Game::factory()->create([
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 105,
        'season' => 2026,
    ]);

    $player = Player::factory()->create();

    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'points' => 28,
    ]);

    // Create already graded prop
    PlayerProp::create([
        'gameable_type' => Game::class,
        'gameable_id' => $game->id,
        'player_id' => $player->id,
        'sport' => 'basketball_nba',
        'player_name' => 'Test Player',
        'market' => 'player_points',
        'line' => 25.5,
        'over_price' => -110,
        'under_price' => -110,
        'graded_at' => now(),
    ]);

    $grader = new GradePlayerProps;
    $results = $grader->execute('basketball_nba', 2026);

    // Should not regrade
    expect($results['graded'])->toBe(0);
});
