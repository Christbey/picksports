<?php

use App\Actions\NBA\CalculateTeamMetrics;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;

uses()->group('nba', 'team-metrics');

beforeEach(function () {
    $this->team = Team::factory()->create(['elo_rating' => 1500]);
    $this->opponent1 = Team::factory()->create(['elo_rating' => 1550]);
    $this->opponent2 = Team::factory()->create(['elo_rating' => 1450]);
});

it('calculates team metrics for a season', function () {
    // Create 2 completed games for the team
    $game1 = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->opponent2->id,
        'away_team_id' => $this->team->id,
        'status' => 'STATUS_FINAL',
    ]);

    // Create team stats with possessions
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'points' => 110,
        'possessions' => 100.0,
        'field_goals_attempted' => 85,
        'offensive_rebounds' => 10,
        'turnovers' => 12,
        'free_throws_attempted' => 20,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
        'points' => 100,
        'possessions' => 98.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'points' => 105,
        'possessions' => 95.0,
        'field_goals_attempted' => 80,
        'offensive_rebounds' => 8,
        'turnovers' => 15,
        'free_throws_attempted' => 18,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
        'points' => 95,
        'possessions' => 96.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull()
        ->team_id->toBe($this->team->id)
        ->season->toBe(2026);

    // Offensive Efficiency: (110 + 105) / (100 + 95) * 100 = 215/195 * 100 = 110.3
    expect($metric->offensive_efficiency)->toBeGreaterThan(105)
        ->toBeLessThan(115);

    // Defensive Efficiency: (100 + 95) / (98 + 96) * 100 = 195/194 * 100 = 100.5
    expect($metric->defensive_efficiency)->toBeGreaterThan(95)
        ->toBeLessThan(105);

    // Net Rating should be positive (team scored more efficiently)
    expect($metric->net_rating)->toBeGreaterThan(0);

    // Tempo: (100 + 95) / 2 = 97.5
    expect($metric->tempo)->toBeGreaterThan(90)
        ->toBeLessThan(105);

    // Strength of Schedule: (1550 + 1450) / 2 = 1500
    expect((float) $metric->strength_of_schedule)->toBe(1500.0);
});

it('returns null when no completed games exist', function () {
    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->toBeNull();
});

it('estimates possessions when not provided', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'status' => 'STATUS_FINAL',
    ]);

    // Create team stat without possessions field
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'points' => 110,
        'possessions' => null,
        'field_goals_attempted' => 85,
        'offensive_rebounds' => 10,
        'turnovers' => 12,
        'free_throws_attempted' => 20,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'points' => 100,
        'possessions' => null,
        'field_goals_attempted' => 80,
        'offensive_rebounds' => 8,
        'turnovers' => 15,
        'free_throws_attempted' => 18,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    // Should calculate metrics using estimated possessions
    expect($metric)->not->toBeNull()
        ->offensive_efficiency->toBeGreaterThan(0)
        ->defensive_efficiency->toBeGreaterThan(0);
});

it('ignores non-final games', function () {
    // Create scheduled game (shouldn't be counted)
    $scheduledGame = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $scheduledGame->id,
        'points' => 110,
        'possessions' => 100.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    // Should return null since no final games
    expect($metric)->toBeNull();
});

it('calculates strength of schedule from opponent elos', function () {
    $opponent1 = Team::factory()->create(['elo_rating' => 1600]);
    $opponent2 = Team::factory()->create(['elo_rating' => 1400]);

    $game1 = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent1->id,
        'status' => 'STATUS_FINAL',
    ]);

    $game2 = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent2->id,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'points' => 110,
        'possessions' => 100.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent1->id,
        'game_id' => $game1->id,
        'points' => 100,
        'possessions' => 98.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'points' => 105,
        'possessions' => 95.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent2->id,
        'game_id' => $game2->id,
        'points' => 95,
        'possessions' => 96.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    // SOS: (1600 + 1400) / 2 = 1500
    expect($metric)->not->toBeNull();
    expect((float) $metric->strength_of_schedule)->toBe(1500.0);
});

it('updates existing metric instead of creating duplicate', function () {
    // Create initial metric
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'points' => 110,
        'possessions' => 100.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'points' => 100,
        'possessions' => 98.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric1 = $action->execute($this->team, 2026);

    expect(TeamMetric::count())->toBe(1);

    // Calculate again (simulating adding more games)
    $metric2 = $action->execute($this->team, 2026);

    // Should update the same record, not create a new one
    expect(TeamMetric::count())->toBe(1);
    expect($metric2->id)->toBe($metric1->id);
});

it('calculates metrics for multiple seasons separately', function () {
    // Game in 2025
    $game2025 = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent1->id,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2025->id,
        'points' => 100,
        'possessions' => 100.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game2025->id,
        'points' => 110,
        'possessions' => 98.0,
    ]);

    // Game in 2026
    $game2026 = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent2->id,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2026->id,
        'points' => 120,
        'possessions' => 95.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2026->id,
        'points' => 90,
        'possessions' => 96.0,
    ]);

    $action = new CalculateTeamMetrics;

    $metric2025 = $action->execute($this->team, 2025);
    $metric2026 = $action->execute($this->team, 2026);

    expect($metric2025)->not->toBeNull()
        ->season->toBe(2025);

    expect($metric2026)->not->toBeNull()
        ->season->toBe(2026);

    // Metrics should be different (2026 had better performance)
    expect($metric2026->offensive_efficiency)->toBeGreaterThan($metric2025->offensive_efficiency);
    expect($metric2026->net_rating)->toBeGreaterThan($metric2025->net_rating);
});
