<?php

use App\Actions\NFL\CalculateTeamMetrics;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;

uses()->group('nfl', 'team-metrics');

beforeEach(function () {
    $this->team = Team::factory()->create(['elo_rating' => 1500]);
    $this->opponent1 = Team::factory()->create(['elo_rating' => 1550]);
    $this->opponent2 = Team::factory()->create(['elo_rating' => 1450]);
});

it('calculates basic team metrics for a season', function () {
    // Create 2 completed games for the team
    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->opponent2->id,
        'away_team_id' => $this->team->id,
        'home_score' => 17,
        'away_score' => 24,
        'status' => 'STATUS_FINAL',
    ]);

    // Create team stats
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'total_yards' => 380,
        'passing_yards' => 250,
        'rushing_yards' => 130,
        'interceptions' => 0,
        'fumbles_lost' => 1,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
        'total_yards' => 320,
        'passing_yards' => 200,
        'rushing_yards' => 120,
        'interceptions' => 1,
        'fumbles_lost' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'total_yards' => 400,
        'passing_yards' => 280,
        'rushing_yards' => 120,
        'interceptions' => 1,
        'fumbles_lost' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
        'total_yards' => 290,
        'passing_yards' => 180,
        'rushing_yards' => 110,
        'interceptions' => 0,
        'fumbles_lost' => 1,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull()
        ->team_id->toBe($this->team->id)
        ->season->toBe(2025);

    // Offensive Rating: (28 + 24) / 2 = 26.0
    expect($metric->offensive_rating)->toBe('26.0');

    // Defensive Rating: (21 + 17) / 2 = 19.0
    expect($metric->defensive_rating)->toBe('19.0');

    // Net Rating: 26.0 - 19.0 = 7.0
    expect($metric->net_rating)->toBe('7.0');

    // Points per game: (28 + 24) / 2 = 26.0
    expect($metric->points_per_game)->toBe('26.0');

    // Points allowed per game: (21 + 17) / 2 = 19.0
    expect($metric->points_allowed_per_game)->toBe('19.0');
});

it('calculates yards metrics correctly', function () {
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'total_yards' => 400,
        'passing_yards' => 280,
        'rushing_yards' => 120,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'total_yards' => 320,
        'passing_yards' => 200,
        'rushing_yards' => 120,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();

    // Yards per game: 400 / 1 = 400.0
    expect($metric->yards_per_game)->toBe('400.0');

    // Yards allowed per game: 320 / 1 = 320.0
    expect($metric->yards_allowed_per_game)->toBe('320.0');

    // Passing yards per game: 280 / 1 = 280.0
    expect($metric->passing_yards_per_game)->toBe('280.0');

    // Rushing yards per game: 120 / 1 = 120.0
    expect($metric->rushing_yards_per_game)->toBe('120.0');
});

it('calculates turnover differential correctly', function () {
    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->opponent2->id,
        'away_team_id' => $this->team->id,
        'home_score' => 17,
        'away_score' => 24,
        'status' => 'STATUS_FINAL',
    ]);

    // Team commits: 1 INT + 1 fumble = 2 turnovers across 2 games
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'total_yards' => 380,
        'interceptions' => 0,  // Team didn't throw INT
        'fumbles_lost' => 1,   // Team lost fumble
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'total_yards' => 400,
        'interceptions' => 1,  // Team threw INT
        'fumbles_lost' => 0,
    ]);

    // Opponents commit: 2 INT + 1 fumble = 3 turnovers across 2 games
    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
        'total_yards' => 320,
        'interceptions' => 1,  // Opponent threw INT
        'fumbles_lost' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
        'total_yards' => 290,
        'interceptions' => 1,  // Opponent threw INT
        'fumbles_lost' => 1,   // Opponent lost fumble
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();

    // Turnover Differential: (3 - 2) / 2 games = +0.5 per game
    expect($metric->turnover_differential)->toBe('0.5');
});

it('calculates negative turnover differential when team commits more turnovers', function () {
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 14,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    // Team commits 3 turnovers
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'total_yards' => 250,
        'interceptions' => 2,
        'fumbles_lost' => 1,
    ]);

    // Opponent commits 1 turnover
    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'total_yards' => 380,
        'interceptions' => 1,
        'fumbles_lost' => 0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();

    // Turnover Differential: (1 - 3) / 1 game = -2.0 per game
    expect($metric->turnover_differential)->toBe('-2.0');
});

it('calculates strength of schedule from opponent elos', function () {
    $opponent1 = Team::factory()->create(['elo_rating' => 1600]);
    $opponent2 = Team::factory()->create(['elo_rating' => 1400]);
    $opponent3 = Team::factory()->create(['elo_rating' => 1500]);

    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent2->id,
        'home_score' => 24,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    $game3 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent3->id,
        'home_score' => 31,
        'away_score' => 20,
        'status' => 'STATUS_FINAL',
    ]);

    // Create minimal stats
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent1->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent2->id,
        'game_id' => $game2->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game3->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent3->id,
        'game_id' => $game3->id,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    // SOS: (1600 + 1400 + 1500) / 3 = 1500.0 (rounded to 3 decimals)
    expect($metric)->not->toBeNull();
    expect((float) $metric->strength_of_schedule)->toBe(1500.0);
});

it('returns null when no completed games exist', function () {
    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->toBeNull();
});

it('returns null when games exist but no team stats', function () {
    Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->toBeNull();
});

it('returns null when completed game stats are missing one side', function () {
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'total_yards' => 380,
    ]);

    $metric = (new CalculateTeamMetrics)->execute($this->team, 2025);

    expect($metric)->toBeNull()
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2025)->exists())->toBeFalse();
});

it('ignores non-final games', function () {
    // Create scheduled game (shouldn't be counted)
    $scheduledGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => null,
        'away_score' => null,
        'status' => 'STATUS_SCHEDULED',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $scheduledGame->id,
        'total_yards' => 380,
    ]);

    // Create in-progress game (shouldn't be counted)
    $liveGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent2->id,
        'home_score' => 14,
        'away_score' => 7,
        'status' => 'STATUS_IN_PROGRESS',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $liveGame->id,
        'total_yards' => 250,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    // Should return null since no final games
    expect($metric)->toBeNull();
});

it('updates existing metric instead of creating duplicate', function () {
    // Create initial game
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'total_yards' => 380,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'total_yards' => 320,
    ]);

    $action = new CalculateTeamMetrics;
    $metric1 = $action->execute($this->team, 2025);

    expect(TeamMetric::count())->toBe(1);

    // Calculate again (simulating adding more games)
    $metric2 = $action->execute($this->team, 2025);

    // Should update the same record, not create a new one
    expect(TeamMetric::count())->toBe(1);
    expect($metric2->id)->toBe($metric1->id);
});

it('calculates metrics for multiple seasons separately', function () {
    // Game in 2024
    $game2024 = Game::factory()->create([
        'season' => 2024,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 17,
        'away_score' => 24,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2024->id,
        'total_yards' => 280,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game2024->id,
        'total_yards' => 380,
    ]);

    // Game in 2025
    $game2025 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent2->id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2025->id,
        'total_yards' => 420,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2025->id,
        'total_yards' => 250,
    ]);

    $action = new CalculateTeamMetrics;

    $metric2024 = $action->execute($this->team, 2024);
    $metric2025 = $action->execute($this->team, 2025);

    expect($metric2024)->not->toBeNull()
        ->season->toBe(2024);

    expect($metric2025)->not->toBeNull()
        ->season->toBe(2025);

    // Metrics should be different (2025 had better performance)
    expect($metric2025->offensive_rating)->toBeGreaterThan($metric2024->offensive_rating);
    expect($metric2025->net_rating)->toBeGreaterThan($metric2024->net_rating);
    expect($metric2025->yards_per_game)->toBeGreaterThan($metric2024->yards_per_game);
});

it('handles teams with only home games', function () {
    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent2->id,
        'home_score' => 24,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    // Average: (28 + 24) / 2 = 26.0
    expect($metric->offensive_rating)->toBe('26.0');
    expect($metric->points_per_game)->toBe('26.0');
});

it('handles teams with only away games', function () {
    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->opponent1->id,
        'away_team_id' => $this->team->id,
        'home_score' => 21,
        'away_score' => 28,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->opponent2->id,
        'away_team_id' => $this->team->id,
        'home_score' => 17,
        'away_score' => 24,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    // Average: (28 + 24) / 2 = 26.0
    expect($metric->offensive_rating)->toBe('26.0');
    expect($metric->points_per_game)->toBe('26.0');
});

it('handles zero turnovers correctly', function () {
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 28,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    // Both teams have no turnovers
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'total_yards' => 380,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'total_yards' => 320,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    // Turnover Differential: (0 - 0) / 1 = 0.0
    expect($metric->turnover_differential)->toBe('0.0');
});

it('calculates nfl power-rating style metrics', function () {
    $this->team->update([
        'elo_rating' => 1600,
        'conference' => 'AFC',
        'division' => 'East',
    ]);

    $topOpponent = Team::factory()->create([
        'elo_rating' => 1900,
        'conference' => 'AFC',
        'division' => 'East',
    ]);

    $lowOpponent = Team::factory()->create([
        'elo_rating' => 1200,
        'conference' => 'AFC',
        'division' => 'West',
    ]);

    $futureOpponent = Team::factory()->create([
        'elo_rating' => 1700,
        'conference' => 'NFC',
        'division' => 'North',
    ]);

    $homeGame = Game::factory()->create([
        'season' => 2025,
        'game_date' => now()->subDays(8),
        'home_team_id' => $this->team->id,
        'away_team_id' => $topOpponent->id,
        'home_score' => 24,
        'away_score' => 20,
        'home_linescores' => [
            ['period' => 1, 'value' => 7],
            ['period' => 2, 'value' => 10],
            ['period' => 3, 'value' => 7],
            ['period' => 4, 'value' => 0],
        ],
        'away_linescores' => [
            ['period' => 1, 'value' => 3],
            ['period' => 2, 'value' => 7],
            ['period' => 3, 'value' => 7],
            ['period' => 4, 'value' => 3],
        ],
        'status' => 'STATUS_FINAL',
    ]);

    $awayGame = Game::factory()->create([
        'season' => 2025,
        'game_date' => now()->subDays(2),
        'home_team_id' => $lowOpponent->id,
        'away_team_id' => $this->team->id,
        'home_score' => 27,
        'away_score' => 17,
        'home_linescores' => [
            ['period' => 1, 'value' => 10],
            ['period' => 2, 'value' => 7],
            ['period' => 3, 'value' => 3],
            ['period' => 4, 'value' => 7],
        ],
        'away_linescores' => [
            ['period' => 1, 'value' => 3],
            ['period' => 2, 'value' => 7],
            ['period' => 3, 'value' => 7],
            ['period' => 4, 'value' => 0],
        ],
        'status' => 'STATUS_FINAL',
    ]);

    Game::factory()->create([
        'season' => 2025,
        'game_date' => now()->addDays(4),
        'home_team_id' => $this->team->id,
        'away_team_id' => $futureOpponent->id,
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);

    foreach ([[$homeGame, $this->team], [$homeGame, $topOpponent], [$awayGame, $this->team], [$awayGame, $lowOpponent]] as [$game, $team]) {
        TeamStat::factory()->create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'total_yards' => 320,
            'passing_yards' => 200,
            'rushing_yards' => 120,
            'interceptions' => 0,
            'fumbles_lost' => 0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    expect((float) $metric->home_rating)->toBe(4.0);
    expect((float) $metric->away_rating)->toBe(-10.0);
    expect((float) $metric->home_advantage_rating)->toBe(14.0);
    expect((float) $metric->season_strength_of_schedule)->toBe(1550.0);
    expect((float) $metric->future_strength_of_schedule)->toBe(1700.0);
    expect((float) $metric->strength_of_schedule_basic)->toBe(0.5);
    expect((float) $metric->in_division_rating)->toBe(4.0);
    expect((float) $metric->non_division_rating)->toBe(-10.0);
    expect((float) $metric->last_5_rating)->toBe(-3.0);
    expect((float) $metric->last_10_rating)->toBe(-3.0);
    expect((float) $metric->first_half_rating)->toBe(0.0);
    expect((float) $metric->second_half_rating)->toBe(-3.0);
    expect($metric->predictive_rating)->not->toBeNull();
    expect($metric->luck_rating)->not->toBeNull();
    expect((float) $metric->consistency_rating)->toBeLessThanOrEqual(100.0);
});

it('keeps first and second half ratings null when linescores are unavailable', function () {
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 21,
        'away_score' => 14,
        'home_linescores' => null,
        'away_linescores' => null,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    expect($metric->first_half_rating)->toBeNull();
    expect($metric->second_half_rating)->toBeNull();
});

it('infers nfl division matchups from abbreviation when conference and division are null', function () {
    $this->team->update([
        'abbreviation' => 'BUF',
        'conference' => null,
        'division' => null,
        'elo_rating' => 1550,
    ]);

    $divOpponent = Team::factory()->create([
        'abbreviation' => 'NYJ',
        'conference' => null,
        'division' => null,
        'elo_rating' => 1500,
    ]);

    $nonDivOpponent = Team::factory()->create([
        'abbreviation' => 'KC',
        'conference' => null,
        'division' => null,
        'elo_rating' => 1600,
    ]);

    $divGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $divOpponent->id,
        'home_score' => 27,
        'away_score' => 20,
        'status' => 'STATUS_FINAL',
    ]);

    $nonDivGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $nonDivOpponent->id,
        'away_team_id' => $this->team->id,
        'home_score' => 24,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
    ]);

    foreach ([[$divGame, $this->team], [$divGame, $divOpponent], [$nonDivGame, $this->team], [$nonDivGame, $nonDivOpponent]] as [$game, $team]) {
        TeamStat::factory()->create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'total_yards' => 300,
            'passing_yards' => 180,
            'rushing_yards' => 120,
            'interceptions' => 0,
            'fumbles_lost' => 0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    expect($metric->in_division_rating)->not->toBeNull();
    expect($metric->non_division_rating)->not->toBeNull();
    expect((float) $metric->in_division_rating)->toBe(7.0);
    expect((float) $metric->non_division_rating)->toBe(-3.0);
});

it('calculates true epa per play team metrics from nfl plays', function () {
    $game1 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'home_score' => 24,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->opponent2->id,
        'away_team_id' => $this->team->id,
        'home_score' => 20,
        'away_score' => 27,
        'status' => 'STATUS_FINAL',
    ]);

    foreach ([[$game1, $this->team], [$game1, $this->opponent1], [$game2, $this->team], [$game2, $this->opponent2]] as [$game, $team]) {
        TeamStat::factory()->create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'total_yards' => 300,
            'passing_yards' => 180,
            'rushing_yards' => 120,
            'interceptions' => 0,
            'fumbles_lost' => 0,
        ]);
    }

    // Team offensive EPA plays: 0.20, -0.10, 0.30 => avg 0.133
    Play::factory()->create([
        'game_id' => $game1->id,
        'possession_team_id' => $this->team->id,
        'is_epa_eligible' => true,
        'true_epa' => 0.20,
    ]);
    Play::factory()->create([
        'game_id' => $game2->id,
        'possession_team_id' => $this->team->id,
        'is_epa_eligible' => true,
        'true_epa' => -0.10,
    ]);
    Play::factory()->create([
        'game_id' => $game2->id,
        'possession_team_id' => $this->team->id,
        'is_epa_eligible' => true,
        'true_epa' => 0.30,
    ]);

    // Opponent offensive EPA vs team defense: -0.20, 0.10, 0.00 => avg -0.033
    Play::factory()->create([
        'game_id' => $game1->id,
        'possession_team_id' => $this->opponent1->id,
        'is_epa_eligible' => true,
        'true_epa' => -0.20,
    ]);
    Play::factory()->create([
        'game_id' => $game2->id,
        'possession_team_id' => $this->opponent2->id,
        'is_epa_eligible' => true,
        'true_epa' => 0.10,
    ]);
    Play::factory()->create([
        'game_id' => $game2->id,
        'possession_team_id' => $this->opponent2->id,
        'is_epa_eligible' => true,
        'true_epa' => 0.00,
    ]);

    // Ineligible or null values should be ignored
    Play::factory()->create([
        'game_id' => $game1->id,
        'possession_team_id' => $this->team->id,
        'is_epa_eligible' => false,
        'true_epa' => 5.00,
    ]);
    Play::factory()->create([
        'game_id' => $game1->id,
        'possession_team_id' => $this->opponent1->id,
        'is_epa_eligible' => true,
        'true_epa' => null,
    ]);
    $unrelatedTeam = Team::factory()->create();
    Play::factory()->create([
        'game_id' => $game1->id,
        'possession_team_id' => $unrelatedTeam->id,
        'is_epa_eligible' => true,
        'true_epa' => 9.99,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2025);

    expect($metric)->not->toBeNull();
    expect((float) $metric->offensive_true_epa_per_play)->toBe(0.133);
    expect((float) $metric->defensive_true_epa_per_play)->toBe(-0.033);
    expect((float) $metric->net_true_epa_per_play)->toBe(0.167);
});
