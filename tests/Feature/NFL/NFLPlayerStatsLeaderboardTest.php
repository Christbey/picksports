<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;

uses()->group('nfl', 'player-stats');

it('returns extended nfl leaderboard stats and limits qbr to qbs', function () {
    $team = Team::factory()->create();

    $qb = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
        'full_name' => 'QB One',
    ]);

    $wr = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'WR',
        'full_name' => 'WR One',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $team->id,
        'away_team_id' => Team::factory()->create()->id,
    ]);

    PlayerStat::create([
        'player_id' => $qb->id,
        'game_id' => $game->id,
        'team_id' => $team->id,
        'passing_completions' => 20,
        'passing_attempts' => 30,
        'passing_yards' => 300,
        'passing_touchdowns' => 3,
        'interceptions_thrown' => 1,
        'sacks_taken' => 2,
        'sack_yards_lost' => 15,
        'passing_two_point_conversions' => 1,
        'passing_long' => 42,
        'rushing_attempts' => 3,
        'rushing_yards' => 20,
        'rushing_touchdowns' => 0,
        'receptions' => 0,
        'receiving_targets' => 0,
        'receiving_yards' => 0,
        'kickoff_returns' => 0,
        'punt_returns' => 0,
    ]);

    PlayerStat::create([
        'player_id' => $wr->id,
        'game_id' => $game->id,
        'team_id' => $team->id,
        'passing_completions' => 3,
        'passing_attempts' => 5,
        'passing_yards' => 40,
        'passing_touchdowns' => 1,
        'interceptions_thrown' => 0,
        'receptions' => 5,
        'receiving_targets' => 8,
        'receiving_yards' => 110,
        'receiving_touchdowns' => 1,
        'receiving_two_point_conversions' => 1,
        'kickoff_returns' => 2,
        'kickoff_return_yards' => 47,
        'kickoff_return_touchdowns' => 0,
        'kickoff_return_long' => 27,
        'kickoff_return_fair_catches' => 1,
        'punt_returns' => 1,
        'punt_return_yards' => 12,
        'punt_return_touchdowns' => 0,
        'punt_return_long' => 12,
        'punt_return_fair_catches' => 0,
    ]);

    $response = $this->getJson('/api/v1/nfl/player-stats/leaderboard?season=2025&min_games=1');

    $response->assertOk();

    $data = collect($response->json('data'));

    $qbEntry = $data->firstWhere('player.id', $qb->id);
    $wrEntry = $data->firstWhere('player.id', $wr->id);

    expect($qbEntry)->not->toBeNull();
    expect($wrEntry)->not->toBeNull();

    expect($qbEntry['qb_rating'])->not->toBeNull();
    expect($qbEntry['qbr'])->not->toBeNull();
    expect($qbEntry['estimated_epa_total'])->toBe(20.43);
    expect($qbEntry['estimated_epa_per_game'])->toBe(20.43);
    expect($qbEntry['estimated_epa_per_opportunity'])->toBe(0.619);
    expect($qbEntry['passing_yards_net_total'])->toBe(285);
    expect($qbEntry['net_yards_per_passing_play'])->toBe(8.91);
    expect((float) $qbEntry['passing_touchdown_percentage'])->toBe(10.0);
    expect((float) $qbEntry['interception_percentage'])->toBe(3.33);
    expect($qbEntry['games_with_interception'])->toBe(1);
    expect($qbEntry['passing_two_point_conversions_total'])->toBe(1);

    expect($wrEntry['qb_rating'])->toBeNull();
    expect($wrEntry['qbr'])->toBeNull();
    expect($wrEntry['estimated_epa_total'])->toBe(25.23);
    expect($wrEntry['estimated_epa_per_game'])->toBe(25.23);
    expect($wrEntry['estimated_epa_per_opportunity'])->toBe(1.577);
    expect($wrEntry['pass_targets_total'])->toBe(8);
    expect($wrEntry['catch_rate'])->toBe(62.5);
    expect($wrEntry['receiving_two_point_conversions_total'])->toBe(1);
    expect($wrEntry['kickoff_returns_total'])->toBe(2);
    expect($wrEntry['punt_returns_total'])->toBe(1);
});
