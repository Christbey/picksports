<?php

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\NFL\HistoricalTeamMetricCalculator;

it('calculates historical nfl team metrics from as-of games and as-of elo', function () {
    $chiefs = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);
    $bills = Team::factory()->create([
        'name' => 'Bills',
        'location' => 'Buffalo',
        'abbreviation' => 'BUF',
    ]);
    $ravens = Team::factory()->create([
        'name' => 'Ravens',
        'location' => 'Baltimore',
        'abbreviation' => 'BAL',
    ]);

    foreach ([
        [$chiefs->id, 1510.0],
        [$bills->id, 1490.0],
        [$ravens->id, 1530.0],
    ] as [$teamId, $elo]) {
        EloRating::query()->create([
            'team_id' => $teamId,
            'game_id' => null,
            'season' => 2024,
            'week' => 18,
            'date' => '2025-07-30',
            'elo_rating' => $elo,
            'elo_change' => 0.0,
        ]);
    }

    $completedGame = Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-09-10 12:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $chiefs->id,
        'away_team_id' => $bills->id,
        'home_score' => 24,
        'away_score' => 17,
    ]);

    TeamStat::query()->create([
        'team_id' => $chiefs->id,
        'game_id' => $completedGame->id,
        'team_type' => 'home',
        'total_yards' => 350,
        'passing_yards' => 220,
        'passing_completions' => 20,
        'passing_attempts' => 31,
        'passing_touchdowns' => 2,
        'interceptions' => 0,
        'rushing_yards' => 130,
        'rushing_attempts' => 25,
        'rushing_touchdowns' => 1,
        'fumbles' => 1,
        'fumbles_lost' => 0,
    ]);
    TeamStat::query()->create([
        'team_id' => $bills->id,
        'game_id' => $completedGame->id,
        'team_type' => 'away',
        'total_yards' => 300,
        'passing_yards' => 210,
        'passing_completions' => 18,
        'passing_attempts' => 30,
        'passing_touchdowns' => 1,
        'interceptions' => 1,
        'rushing_yards' => 90,
        'rushing_attempts' => 21,
        'rushing_touchdowns' => 1,
        'fumbles' => 2,
        'fumbles_lost' => 1,
    ]);

    EloRating::query()->create([
        'team_id' => $chiefs->id,
        'game_id' => $completedGame->id,
        'season' => 2025,
        'week' => 1,
        'date' => '2025-09-10',
        'elo_rating' => 1520.0,
        'elo_change' => 10.0,
    ]);
    EloRating::query()->create([
        'team_id' => $bills->id,
        'game_id' => $completedGame->id,
        'season' => 2025,
        'week' => 1,
        'date' => '2025-09-10',
        'elo_rating' => 1480.0,
        'elo_change' => -10.0,
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-09-20 12:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $chiefs->id,
        'away_team_id' => $ravens->id,
        'home_score' => 20,
        'away_score' => 23,
    ]);

    $rows = app(HistoricalTeamMetricCalculator::class)->calculateForDate(2025, '2025-09-15T12:00:00Z');

    expect($rows)->toHaveCount(3)
        ->and($rows[$chiefs->id]['wins'])->toBe(1)
        ->and($rows[$chiefs->id]['losses'])->toBe(0)
        ->and($rows[$chiefs->id]['recent_form_rating'])->toBe(7.0)
        ->and($rows[$chiefs->id]['future_strength_of_schedule'])->toBe(1530.0)
        ->and($rows[$chiefs->id]['predictive_rating'])->toBe(7.0)
        ->and($rows[$chiefs->id]['injury_total_adjustment'])->toBe(0.0);
});

it('uses prior-season metrics as preseason priors for future seasons', function () {
    $chiefs = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);
    $bills = Team::factory()->create([
        'name' => 'Bills',
        'location' => 'Buffalo',
        'abbreviation' => 'BUF',
    ]);

    \App\Models\NFL\TeamMetric::query()->create([
        'team_id' => $chiefs->id,
        'season' => 2025,
        'wins' => 12,
        'losses' => 5,
        'predictive_rating' => 8.5,
        'recent_form_rating' => 6.0,
        'future_strength_of_schedule' => 1498.0,
        'calculation_date' => '2026-01-10',
    ]);
    \App\Models\NFL\TeamMetric::query()->create([
        'team_id' => $bills->id,
        'season' => 2025,
        'wins' => 10,
        'losses' => 7,
        'predictive_rating' => 2.0,
        'recent_form_rating' => 1.5,
        'future_strength_of_schedule' => 1502.0,
        'calculation_date' => '2026-01-10',
    ]);

    EloRating::query()->create([
        'team_id' => $bills->id,
        'game_id' => null,
        'season' => 2025,
        'week' => 18,
        'date' => '2026-01-10',
        'elo_rating' => 1525.0,
        'elo_change' => 0.0,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2026-09-10 12:00:00',
        'status' => config('nfl.statuses.scheduled'),
        'home_team_id' => $chiefs->id,
        'away_team_id' => $bills->id,
    ]);

    $rows = app(HistoricalTeamMetricCalculator::class)->calculateForDate(2026, '2026-08-01T12:00:00Z');

    expect($rows[$chiefs->id]['wins'])->toBe(0)
        ->and($rows[$chiefs->id]['losses'])->toBe(0)
        ->and($rows[$chiefs->id]['predictive_rating'])->toBe(8.5)
        ->and($rows[$chiefs->id]['recent_form_rating'])->toBe(2.1)
        ->and($rows[$chiefs->id]['future_strength_of_schedule'])->toBe(1525.0)
        ->and($rows[$chiefs->id]['injury_total_adjustment'])->toBe(0.0);
});

it('blends multiple prior seasons into preseason priors with decay', function () {
    config()->set('nfl.team_futures.preseason_prior_lookback_seasons', 2);
    config()->set('nfl.team_futures.preseason_prior_season_decay', 0.50);
    config()->set('nfl.team_futures.preseason_prior_predictive_decay', 1.0);
    config()->set('nfl.team_futures.preseason_prior_recent_form_decay', 1.0);

    $chiefs = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);
    $bills = Team::factory()->create([
        'name' => 'Bills',
        'location' => 'Buffalo',
        'abbreviation' => 'BUF',
    ]);

    \App\Models\NFL\TeamMetric::query()->create([
        'team_id' => $chiefs->id,
        'season' => 2024,
        'wins' => 13,
        'losses' => 4,
        'predictive_rating' => 10.0,
        'recent_form_rating' => 8.0,
        'future_strength_of_schedule' => 1495.0,
        'calculation_date' => '2025-01-12',
    ]);
    \App\Models\NFL\TeamMetric::query()->create([
        'team_id' => $chiefs->id,
        'season' => 2023,
        'wins' => 11,
        'losses' => 6,
        'predictive_rating' => 4.0,
        'recent_form_rating' => 2.0,
        'future_strength_of_schedule' => 1500.0,
        'calculation_date' => '2024-01-12',
    ]);

    EloRating::query()->create([
        'team_id' => $bills->id,
        'game_id' => null,
        'season' => 2024,
        'week' => 18,
        'date' => '2025-01-12',
        'elo_rating' => 1515.0,
        'elo_change' => 0.0,
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-09-10 12:00:00',
        'status' => config('nfl.statuses.scheduled'),
        'home_team_id' => $chiefs->id,
        'away_team_id' => $bills->id,
    ]);

    $rows = app(HistoricalTeamMetricCalculator::class)->calculateForDate(2025, '2025-08-01T12:00:00Z');

    expect($rows[$chiefs->id]['predictive_rating'])->toBe(8.0)
        ->and($rows[$chiefs->id]['recent_form_rating'])->toBe(6.0)
        ->and($rows[$chiefs->id]['future_strength_of_schedule'])->toBe(1515.0)
        ->and($rows[$chiefs->id]['wins'])->toBe(0)
        ->and($rows[$chiefs->id]['losses'])->toBe(0);
});
