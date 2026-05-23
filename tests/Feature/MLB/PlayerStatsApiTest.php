<?php

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;

uses()->group('mlb', 'player-stats');

it('returns filtered mlb player game logs with matchup teams', function () {
    $homeTeam = Team::factory()->create(['abbreviation' => 'STL']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'CHC']);
    $player = Player::factory()->create(['team_id' => $homeTeam->id]);

    $regularGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-05-23',
    ]);

    $springGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.spring_training'),
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-03-10',
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $regularGame->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'batting',
        'hits' => 2,
        'at_bats' => 4,
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $player->id,
        'game_id' => $regularGame->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'pitching',
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $springGame->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'batting',
        'hits' => 4,
        'at_bats' => 5,
    ]);

    $response = $this->getJson("/api/v1/mlb/players/{$player->id}/stats?season=2026&season_type=2&stat_type=batting&per_page=200");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.stat_type', 'batting')
        ->assertJsonPath('data.0.hits', 2)
        ->assertJsonPath('data.0.game.home_team.abbreviation', 'STL')
        ->assertJsonPath('data.0.game.away_team.abbreviation', 'CHC');
});

it('orders filtered mlb player game logs by game date instead of stat id', function () {
    $homeTeam = Team::factory()->create(['abbreviation' => 'STL']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'CIN']);
    $player = Player::factory()->create(['team_id' => $homeTeam->id]);

    $newerGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
        'game_date' => '2026-05-23',
    ]);

    $olderGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-05-12',
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $newerGame->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'batting',
        'hits' => 1,
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $olderGame->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'batting',
        'hits' => 2,
    ]);

    $response = $this->getJson("/api/v1/mlb/players/{$player->id}/stats?season=2026&season_type=2&stat_type=batting&per_page=200");

    $response->assertOk()
        ->assertJsonPath('data.0.game.game_date', '2026-05-23')
        ->assertJsonPath('data.1.game.game_date', '2026-05-12');
});

it('filters mlb leaderboard by stat type', function () {
    $team = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => config('mlb.statuses.final'),
        'home_team_id' => $team->id,
        'away_team_id' => Team::factory()->create()->id,
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $game->id,
        'team_id' => $team->id,
        'stat_type' => 'batting',
        'hits' => 3,
        'at_bats' => 4,
    ]);

    PlayerStat::factory()->pitching()->create([
        'player_id' => $player->id,
        'game_id' => $game->id,
        'team_id' => $team->id,
        'stat_type' => 'pitching',
        'strikeouts_pitched' => 9,
    ]);

    $response = $this->getJson('/api/v1/mlb/player-stats/leaderboard?season=2026&season_type=2&stat_type=batting&min_games=1');

    $response->assertOk()
        ->assertJsonPath('data.0.player_id', $player->id)
        ->assertJsonPath('data.0.games_played', 1)
        ->assertJsonPath('data.0.points_per_game', 3);
});
