<?php

use App\Actions\WCBB\CalculateTeamMetrics;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;

uses()->group('wcbb', 'team-metrics');

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->opponent1 = Team::factory()->create();
    $this->opponent2 = Team::factory()->create();
});

it('calculates basic team metrics for a season', function () {
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
        'points' => 80,
        'possessions' => 70.0,
        'field_goals_attempted' => 60,
        'offensive_rebounds' => 8,
        'turnovers' => 10,
        'free_throws_attempted' => 15,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game1->id,
        'points' => 75,
        'possessions' => 68.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'points' => 85,
        'possessions' => 72.0,
        'field_goals_attempted' => 62,
        'offensive_rebounds' => 10,
        'turnovers' => 12,
        'free_throws_attempted' => 18,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2->id,
        'points' => 70,
        'possessions' => 70.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull()
        ->team_id->toBe($this->team->id)
        ->season->toBe(2026)
        ->games_played->toBe(2);

    // Offensive Efficiency: (80 + 85) / (70 + 72) * 100 = 165/142 * 100 = 116.2
    expect($metric->offensive_efficiency)->toBeGreaterThan(110)
        ->toBeLessThan(120);

    // Defensive Efficiency: (75 + 70) / (68 + 70) * 100 = 145/138 * 100 = 105.1
    expect($metric->defensive_efficiency)->toBeGreaterThan(100)
        ->toBeLessThan(110);

    // Net Rating should be positive (team scored more efficiently)
    expect($metric->net_rating)->toBeGreaterThan(0);

    // Tempo: (70 + 72) / 2 = 71.0
    expect($metric->tempo)->toBeGreaterThan(68)
        ->toBeLessThan(74);

    // Strength of Schedule: WCBB teams don't have ELO ratings in test environment
    // So this will be null unless opponents have ELO ratings set
    // (Not testing SOS here since it requires database schema changes)

    // Possession coefficient should be saved
    expect((float) $metric->possession_coefficient)->toBe(config('wcbb.metrics.possession_coefficient'));
});

it('returns null when no completed games exist', function () {
    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->toBeNull();
});

it('calculates rolling window metrics', function () {
    $rollingWindowSize = config('wcbb.metrics.rolling_window_size');

    // Create more games than rolling window size
    for ($i = 0; $i < $rollingWindowSize + 5; $i++) {
        $opponent = Team::factory()->create();
        $game = Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
        ]);

        // Earlier games: lower performance
        // Later games: higher performance
        $points = $i < $rollingWindowSize ? 70 : 90;

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'points' => $points,
            'possessions' => 70.0,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'points' => 75,
            'possessions' => 68.0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull();
    expect($metric->rolling_games_count)->toBe($rollingWindowSize);

    // Rolling offensive efficiency should be higher than season-long
    // because recent games had better performance
    expect($metric->rolling_offensive_efficiency)->toBeGreaterThan($metric->offensive_efficiency);
});

it('calculates home and away splits', function () {
    // Create 3 home games
    for ($i = 0; $i < 3; $i++) {
        $opponent = Team::factory()->create();
        $game = Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'points' => 90,
            'possessions' => 70.0,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'points' => 70,
            'possessions' => 68.0,
        ]);
    }

    // Create 2 away games with worse performance
    for ($i = 0; $i < 2; $i++) {
        $opponent = Team::factory()->create();
        $game = Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $opponent->id,
            'away_team_id' => $this->team->id,
            'status' => 'STATUS_FINAL',
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'points' => 75,
            'possessions' => 70.0,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'points' => 80,
            'possessions' => 68.0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull();
    expect($metric->home_games)->toBe(3);
    expect($metric->away_games)->toBe(2);

    // Home offensive efficiency should be better than away
    expect($metric->home_offensive_efficiency)->toBeGreaterThan($metric->away_offensive_efficiency);

    // Away defensive efficiency should be worse than home
    expect($metric->away_defensive_efficiency)->toBeGreaterThan($metric->home_defensive_efficiency);
});

it('respects minimum games threshold', function () {
    $minimumGames = config('wcbb.metrics.minimum_games');

    // Create games less than minimum threshold
    for ($i = 0; $i < $minimumGames - 1; $i++) {
        $opponent = Team::factory()->create();
        $game = Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'points' => 80,
            'possessions' => 70.0,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'points' => 75,
            'possessions' => 68.0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull();
    expect($metric->meets_minimum)->toBeFalse();
    // Metrics are still calculated even if under minimum, but meets_minimum flag is false
    expect($metric->offensive_efficiency)->toBeGreaterThan(0);
    expect($metric->defensive_efficiency)->toBeGreaterThan(0);
    expect($metric->net_rating)->not->toBeNull();
    expect($metric->tempo)->toBeGreaterThan(0);
});

it('marks metrics as meeting minimum when threshold is met', function () {
    $minimumGames = config('wcbb.metrics.minimum_games');

    // Create exactly minimum games
    for ($i = 0; $i < $minimumGames; $i++) {
        $opponent = Team::factory()->create();
        $game = Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'points' => 80,
            'possessions' => 70.0,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'points' => 75,
            'possessions' => 68.0,
        ]);
    }

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    expect($metric)->not->toBeNull();
    expect($metric->meets_minimum)->toBeTrue();
    expect($metric->offensive_efficiency)->not->toBeNull();
    expect($metric->defensive_efficiency)->not->toBeNull();
    expect($metric->net_rating)->not->toBeNull();
    expect($metric->tempo)->not->toBeNull();
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
        'points' => 80,
        'possessions' => null,
        'field_goals_attempted' => 60,
        'offensive_rebounds' => 8,
        'turnovers' => 10,
        'free_throws_attempted' => 15,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'points' => 75,
        'possessions' => null,
        'field_goals_attempted' => 58,
        'offensive_rebounds' => 10,
        'turnovers' => 12,
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
        'points' => 80,
        'possessions' => 70.0,
    ]);

    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);

    // Should return null since no final games
    expect($metric)->toBeNull();
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
        'points' => 80,
        'possessions' => 70.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game->id,
        'points' => 75,
        'possessions' => 68.0,
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
        'points' => 70,
        'possessions' => 70.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent1->id,
        'game_id' => $game2025->id,
        'points' => 80,
        'possessions' => 68.0,
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
        'points' => 90,
        'possessions' => 70.0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->opponent2->id,
        'game_id' => $game2026->id,
        'points' => 70,
        'possessions' => 68.0,
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

it('calculates opponent-adjusted metrics for all teams', function () {
    $minimumGames = config('wcbb.metrics.minimum_games');

    // Create 3 teams with enough games to meet minimum
    $teams = collect([
        $this->team,
        $this->opponent1,
        $this->opponent2,
    ]);

    // Create a round-robin schedule (each team plays each other)
    foreach ($teams as $homeTeam) {
        foreach ($teams as $awayTeam) {
            if ($homeTeam->id === $awayTeam->id) {
                continue;
            }

            // Create multiple games to meet minimum threshold
            for ($i = 0; $i < ceil($minimumGames / 2); $i++) {
                $game = Game::factory()->create([
                    'season' => 2026,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'status' => 'STATUS_FINAL',
                ]);

                TeamStat::factory()->create([
                    'team_id' => $homeTeam->id,
                    'game_id' => $game->id,
                    'points' => 80,
                    'possessions' => 70.0,
                ]);

                TeamStat::factory()->create([
                    'team_id' => $awayTeam->id,
                    'game_id' => $game->id,
                    'points' => 75,
                    'possessions' => 68.0,
                ]);
            }
        }
    }

    $action = new CalculateTeamMetrics;
    $calculated = $action->executeForAllTeams(2026);

    expect($calculated)->toBeGreaterThan(0);

    // Check that adjusted metrics were calculated
    $metric = TeamMetric::where('team_id', $this->team->id)
        ->where('season', 2026)
        ->first();

    expect($metric)->not->toBeNull();
    expect($metric->meets_minimum)->toBeTrue();
    expect($metric->adj_offensive_efficiency)->not->toBeNull();
    expect($metric->adj_defensive_efficiency)->not->toBeNull();
    expect($metric->adj_net_rating)->not->toBeNull();
    expect($metric->adj_tempo)->not->toBeNull();
    expect($metric->iteration_count)->toBeGreaterThan(0);

    // Adjusted values should be normalized to 100
    $allMetrics = TeamMetric::where('season', 2026)
        ->where('meets_minimum', true)
        ->get();

    $avgAdjOffense = $allMetrics->avg('adj_offensive_efficiency');
    $avgAdjDefense = $allMetrics->avg('adj_defensive_efficiency');

    // Averages should be close to 100 (normalization baseline)
    expect($avgAdjOffense)->toBeGreaterThan(99)->toBeLessThan(101);
    expect($avgAdjDefense)->toBeGreaterThan(99)->toBeLessThan(101);
});
