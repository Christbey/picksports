<?php

use App\Actions\MLB\CalculateTeamMetrics;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\MLB\TeamStat;

beforeEach(function () {
    $this->action = new CalculateTeamMetrics;
    $this->team = Team::factory()->create(['elo_rating' => 1500]);
    $this->season = 2024;
});

it('calculates baseball metrics correctly for a single game', function () {
    $opponent = Team::factory()->create(['elo_rating' => 1600]);

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 0,
        'away_score' => 0,
    ]);

    // Create team stats for the game
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'runs' => 5,
        'hits' => 10,
        'at_bats' => 35,
        'walks' => 4,
        'doubles' => 2,
        'triples' => 0,
        'home_runs' => 2,
        'strikeouts' => 8,
        'innings_pitched' => 9,
        'earned_runs' => 3,
        'hits_allowed' => 7,
        'strikeouts_pitched' => 9,
        'walks_allowed' => 3,
        'putouts' => 27,
        'assists' => 12,
        'errors' => 1,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'runs' => 3,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeInstanceOf(TeamMetric::class)
        ->and($metric->team_id)->toBe($this->team->id)
        ->and($metric->season)->toBe($this->season)
        ->and($metric->offensive_rating)->toBeGreaterThan(0)
        ->and($metric->pitching_rating)->toBeGreaterThan(0)
        ->and($metric->defensive_rating)->toBeGreaterThan(0)
        ->and($metric->runs_per_game)->toBe(5.0)
        ->and($metric->runs_allowed_per_game)->toBe(3.0)
        ->and($metric->run_differential_per_game)->toBe(2.0)
        ->and($metric->wins)->toBe(1)
        ->and($metric->losses)->toBe(0)
        ->and($metric->home_runs_per_game)->toBe(2.0)
        ->and($metric->batting_average)->toBe(0.286) // 10/35 rounded to 3 decimals
        ->and($metric->on_base_percentage)->toBe(0.359) // (10 + 4) / (35 + 4)
        ->and($metric->slugging_percentage)->toBe(0.514) // (6 singles + 2 doubles + 2 HR*4) / 35
        ->and($metric->ops)->toBe(0.873) // OBP + SLG
        ->and($metric->team_era)->toBe(3.0) // (3/9)*9
        ->and($metric->strikeouts_pitched_per_game)->toBe(9.0)
        ->and($metric->whip)->toBe(1.111) // (7 + 3) / 9
        ->and((float) $metric->strength_of_schedule)->toBe(1600.0)
        ->and($metric->calculation_date->toDateString())->toBe(now()->toDateString());
});

it('calculates batting average correctly across multiple games', function () {
    $opponent = Team::factory()->create();

    $game1 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'game_date' => "{$this->season}-06-01",
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    $game2 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'game_date' => "{$this->season}-06-02",
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->team->id,
    ]);

    // Game 1: 8 hits in 30 at bats
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'hits' => 8,
        'at_bats' => 30,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game1->id,
    ]);

    // Game 2: 10 hits in 34 at bats
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'hits' => 10,
        'at_bats' => 34,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game2->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Total: 18 hits / 64 at bats = 0.281
    expect($metric->batting_average)->toBe(0.281);
});

it('calculates official on base percentage when hbp and sacrifice flies are available', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'game_date' => "{$this->season}-06-01",
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'hits' => 9,
        'walks' => 4,
        'hit_by_pitch' => 2,
        'sacrifice_flies' => 1,
        'at_bats' => 34,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'runs' => 3,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Official OBP: (H + BB + HBP) / (AB + BB + HBP + SF) = 15 / 41 = .366.
    expect($metric)->toBeInstanceOf(TeamMetric::class)
        ->and($metric->on_base_percentage)->toBe(0.366);
});

it('does not save season metrics when completed game team stats are incomplete', function () {
    $opponent = Team::factory()->create();

    $gameWithStats = Game::factory()->create([
        'season' => $this->season,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 5,
        'away_score' => 3,
        'game_date' => "{$this->season}-04-01",
    ]);

    Game::factory()->create([
        'season' => $this->season,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->team->id,
        'home_score' => 6,
        'away_score' => 1,
        'game_date' => "{$this->season}-04-02",
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $gameWithStats->id,
        'team_type' => 'home',
        'runs' => 5,
        'hits' => 10,
        'at_bats' => 35,
        'innings_pitched' => 9,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $gameWithStats->id,
        'team_type' => 'away',
        'runs' => 3,
        'hits' => 7,
        'at_bats' => 33,
        'innings_pitched' => 9,
    ]);

    $metric = $this->action->execute($this->team, $this->season, config('mlb.season.types.regular', 2));

    expect($metric)->toBeNull()
        ->and(TeamMetric::query()
            ->where('team_id', $this->team->id)
            ->where('season', $this->season)
            ->where('season_type', (string) config('mlb.season.types.regular', 2))
            ->exists())->toBeFalse();
});

it('calculates team ERA correctly across multiple games', function () {
    $opponent = Team::factory()->create();

    $game1 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    $game2 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->team->id,
    ]);

    // Game 1: 3 earned runs in 9 innings
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'earned_runs' => 3,
        'innings_pitched' => 9,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game1->id,
    ]);

    // Game 2: 5 earned runs in 8 innings
    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'earned_runs' => 5,
        'innings_pitched' => 8,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game2->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Total: (8 earned runs / 17 innings) * 9 = 4.24
    expect($metric->team_era)->toBe(4.24);
});

it('normalizes baseball innings notation when calculating pitching metrics', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'game_date' => "{$this->season}-06-01",
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'runs' => 5,
        'earned_runs' => 3,
        'innings_pitched' => 8.1,
        'hits_allowed' => 8,
        'walks_allowed' => 2,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'runs' => 3,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeInstanceOf(TeamMetric::class)
        ->and($metric->team_era)->toBe(3.24)
        ->and($metric->whip)->toBe(1.2);
});

it('calculates runs per game correctly', function () {
    $opponent = Team::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $game = Game::factory()->create([
            'season' => $this->season,
            'week' => 13,
            'status' => 'STATUS_FINAL',
            'game_date' => "{$this->season}-06-0".($i + 1),
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'runs' => 4 + $i, // 4, 5, 6 runs
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
        ]);
    }

    $metric = $this->action->execute($this->team, $this->season);

    // Average: (4 + 5 + 6) / 3 = 5.0
    expect($metric->runs_per_game)->toBe(5.0);
});

it('calculates runs allowed per game correctly', function () {
    $opponent = Team::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $game = Game::factory()->create([
            'season' => $this->season,
            'status' => 'STATUS_FINAL',
            'game_date' => "{$this->season}-06-0".($i + 1),
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'runs' => 2 + $i, // Opponent scored 2, 3, 4 runs
        ]);
    }

    $metric = $this->action->execute($this->team, $this->season);

    // Average: (2 + 3 + 4) / 3 = 3.0
    expect($metric->runs_allowed_per_game)->toBe(3.0);
});

it('ignores opening-day spring training finals when calculating current-season metrics', function () {
    $opponent = Team::factory()->create();

    $springGame = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $springGame->id,
        'runs' => 9,
        'hits' => 14,
        'at_bats' => 36,
    ]);
    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $springGame->id,
        'runs' => 3,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    $metric = $this->action->execute($this->team, 2026);

    expect($metric)->toBeNull()
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2026)->exists())->toBeFalse();
});

it('calculates offensive rating based on runs, batting, and power', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'runs' => 6,
        'hits' => 12,
        'at_bats' => 36,
        'walks' => 5,
        'home_runs' => 3,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Formula: (runs_per_game * 20) + (batting_avg * 100) + (home_run_rate * 10)
    // (6 * 20) + (0.333 * 100) + (3 * 10) = 120 + 33.3 + 30 = 183.3
    expect($metric->offensive_rating)->toBeGreaterThan(180)
        ->and($metric->offensive_rating)->toBeLessThan(185);
});

it('calculates pitching rating based on ERA, strikeouts, and walks', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'earned_runs' => 2,
        'innings_pitched' => 9,
        'strikeouts_pitched' => 10,
        'walks_allowed' => 2,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // ERA = (2/9)*9 = 2.0
    // ERA component = max(0, 100 - (2.0 * 10)) = 80
    // K's per game = 10
    // Walks per game = 2
    // Rating = 80 + 10 - 2 = 88
    expect($metric->pitching_rating)->toBe(88.0);
});

it('calculates defensive rating based on fielding percentage and plays', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'putouts' => 27,
        'assists' => 15,
        'errors' => 1,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Fielding % = (27 + 15 - 1) / (27 + 15 + 1) = 41/43 = 0.9535
    // Rating = (0.9535 * 100) + 27 + 15 - (1 * 10) = 95.35 + 27 + 15 - 10 = 127.35
    expect($metric->defensive_rating)->toBeGreaterThan(125)
        ->and($metric->defensive_rating)->toBeLessThan(130);
});

it('calculates strength of schedule based on opponent ELO ratings', function () {
    $opponent1 = Team::factory()->create(['elo_rating' => 1600]);
    $opponent2 = Team::factory()->create(['elo_rating' => 1400]);
    $opponent3 = Team::factory()->create(['elo_rating' => 1500]);

    foreach ([$opponent1, $opponent2, $opponent3] as $opponent) {
        $game = Game::factory()->create([
            'season' => $this->season,
            'status' => 'STATUS_FINAL',
            'home_team_id' => $this->team->id,
            'away_team_id' => $opponent->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
        ]);
    }

    $metric = $this->action->execute($this->team, $this->season);

    // Average: (1600 + 1400 + 1500) / 3 = 1500
    expect((float) $metric->strength_of_schedule)->toBe(1500.0);
});

it('calculates rest travel fatigue from compressed schedule and venue changes', function () {
    $opponent = Team::factory()->create();

    foreach ([
        ['date' => "{$this->season}-06-01", 'home' => true, 'city' => 'Kansas City', 'state' => 'MO'],
        ['date' => "{$this->season}-06-02", 'home' => false, 'city' => 'Chicago', 'state' => 'IL'],
        ['date' => "{$this->season}-06-03", 'home' => false, 'city' => 'Chicago', 'state' => 'IL'],
        ['date' => "{$this->season}-06-06", 'home' => true, 'city' => 'Kansas City', 'state' => 'MO'],
    ] as $index => $row) {
        $game = Game::factory()->create([
            'season' => $this->season,
            'status' => 'STATUS_FINAL',
            'game_date' => $row['date'],
            'home_team_id' => $row['home'] ? $this->team->id : $opponent->id,
            'away_team_id' => $row['home'] ? $opponent->id : $this->team->id,
            'venue_city' => $row['city'],
            'venue_state' => $row['state'],
            'venue_name' => "{$row['city']} Ballpark",
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->team->id,
            'game_id' => $game->id,
            'team_type' => $row['home'] ? 'home' : 'away',
            'runs' => 5 + $index,
        ]);
        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
            'team_type' => $row['home'] ? 'away' : 'home',
            'runs' => 3,
        ]);
    }

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeInstanceOf(TeamMetric::class)
        ->and((float) $metric->rest_travel_fatigue)->toBeGreaterThan(0.0)
        ->and((float) $metric->rest_travel_fatigue)->toBeLessThan(10.0);
});

it('returns null when team has no completed games', function () {
    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeNull();
});

it('returns null when team has games but no team stats', function () {
    $opponent = Team::factory()->create();

    Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeNull();
});

it('ignores non-final games', function () {
    $opponent = Team::factory()->create();

    $scheduledGame = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $scheduledGame->id,
        'runs' => 10,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $scheduledGame->id,
    ]);

    $inProgressGame = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_IN_PROGRESS',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $inProgressGame->id,
        'runs' => 8,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $inProgressGame->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeNull();
});

it('only includes games from the specified season', function () {
    $opponent = Team::factory()->create();

    $currentSeasonGame = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $currentSeasonGame->id,
        'runs' => 5,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $currentSeasonGame->id,
        'runs' => 3,
    ]);

    $previousSeasonGame = Game::factory()->create([
        'season' => $this->season - 1,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $previousSeasonGame->id,
        'runs' => 10,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $previousSeasonGame->id,
        'runs' => 8,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric->runs_per_game)->toBe(5.0)
        ->and($metric->runs_allowed_per_game)->toBe(3.0);
});

it('handles games where team is home or away', function () {
    $opponent = Team::factory()->create();

    $homeGame = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $homeGame->id,
        'team_type' => 'home',
        'runs' => 4,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $homeGame->id,
        'team_type' => 'away',
        'runs' => 2,
    ]);

    $awayGame = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->team->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $awayGame->id,
        'team_type' => 'away',
        'runs' => 6,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $awayGame->id,
        'team_type' => 'home',
        'runs' => 5,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    // Average runs: (4 + 6) / 2 = 5.0
    // Average runs allowed: (2 + 5) / 2 = 3.5
    expect($metric->runs_per_game)->toBe(5.0)
        ->and($metric->runs_allowed_per_game)->toBe(3.5);
});

it('handles zero values gracefully in batting average calculation', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'hits' => 0,
        'at_bats' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric->batting_average)->toBe(0.0);
});

it('handles zero values gracefully in ERA calculation', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'earned_runs' => 0,
        'innings_pitched' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric->team_era)->toBe(0.0);
});

it('uses updateOrCreate to avoid duplicate metrics', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'runs' => 5,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'runs' => 3,
    ]);

    $firstMetric = $this->action->execute($this->team, $this->season);
    $secondMetric = $this->action->execute($this->team, $this->season);

    expect($firstMetric->id)->toBe($secondMetric->id)
        ->and(TeamMetric::where('team_id', $this->team->id)->where('season', $this->season)->count())->toBe(1);
});

it('updates existing metrics when new games are added', function () {
    $opponent = Team::factory()->create();

    $game1 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game1->id,
        'runs' => 4,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game1->id,
        'runs' => 2,
    ]);

    $firstMetric = $this->action->execute($this->team, $this->season);

    expect($firstMetric->runs_per_game)->toBe(4.0);

    $game2 = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game2->id,
        'runs' => 6,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game2->id,
        'runs' => 5,
    ]);

    $updatedMetric = $this->action->execute($this->team, $this->season);

    expect($updatedMetric->id)->toBe($firstMetric->id)
        ->and($updatedMetric->runs_per_game)->toBe(5.0); // (4 + 6) / 2
});

it('calculates metrics for multiple seasons independently', function () {
    $opponent = Team::factory()->create();

    $season2024Game = Game::factory()->create([
        'season' => 2024,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $season2024Game->id,
        'runs' => 5,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $season2024Game->id,
    ]);

    $season2025Game = Game::factory()->create([
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $season2025Game->id,
        'runs' => 8,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $season2025Game->id,
    ]);

    $metric2024 = $this->action->execute($this->team, 2024);
    $metric2025 = $this->action->execute($this->team, 2025);

    expect($metric2024->season)->toBe(2024)
        ->and($metric2024->runs_per_game)->toBe(5.0)
        ->and($metric2025->season)->toBe(2025)
        ->and($metric2025->runs_per_game)->toBe(8.0);
});

it('executes for all teams and returns count of calculated metrics', function () {
    $teams = Team::factory()->count(3)->create();
    $opponent = Team::factory()->create();

    foreach ($teams as $team) {
        $game = Game::factory()->create([
            'season' => $this->season,
            'season_type' => config('mlb.season.types.regular'),
            'week' => 2,
            'game_date' => '2024-04-01',
            'status' => 'STATUS_FINAL',
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $team->id,
            'game_id' => $game->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $game->id,
        ]);
    }

    $count = $this->action->executeForAllTeams($this->season);

    expect($count)->toBe(4); // 3 teams + 1 opponent
});

it('returns null for strength of schedule when no opponents have ELO ratings', function () {
    $opponent = Team::factory()->create(['elo_rating' => null]);

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect((float) $metric->strength_of_schedule)->toBe(0.0);
});

it('handles null values in stats gracefully', function () {
    $opponent = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => $this->season,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $this->team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'runs' => null,
        'hits' => null,
        'at_bats' => null,
        'earned_runs' => null,
        'innings_pitched' => null,
        'putouts' => null,
        'assists' => null,
        'errors' => null,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'runs' => null,
    ]);

    $metric = $this->action->execute($this->team, $this->season);

    expect($metric)->toBeInstanceOf(TeamMetric::class)
        ->and($metric->runs_per_game)->toBe(0.0)
        ->and($metric->runs_allowed_per_game)->toBe(0.0)
        ->and($metric->batting_average)->toBe(0.0)
        ->and($metric->team_era)->toBe(0.0);
});
