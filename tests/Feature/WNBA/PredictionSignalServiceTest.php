<?php

use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamStat;
use App\Services\WNBA\WnbaPredictionSignalService;

uses()->group('wnba', 'prediction-signals');

it('builds rest and fatigue signals from prior games only', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $opponent = Team::factory()->create();

    createWnbaSignalGame($homeTeam, $opponent, '2026-07-07', '20:00:00');
    createWnbaSignalGame($homeTeam, $opponent, '2026-07-09', '20:00:00');
    createWnbaSignalGame($awayTeam, $opponent, '2026-07-07', '20:00:00');
    createWnbaSignalGame($awayTeam, $opponent, '2026-07-10', '23:30:00');

    $target = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('wnba.season.types.regular', 2),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-10',
        'game_time' => '22:00:00',
    ]);

    $signals = app(WnbaPredictionSignalService::class)->forGame($target);

    expect($signals['home']['rest'])
        ->days->toBe(0)
        ->back_to_back->toBeTrue()
        ->games_last_5_days_including_today->toBe(3)
        ->three_in_five->toBeTrue()
        ->and($signals['away']['rest'])
        ->days->toBe(2)
        ->back_to_back->toBeFalse()
        ->three_in_five->toBeFalse()
        ->and($signals['differentials']['rest_days'])->toBe(-2.0);
});

it('summarizes rolling four-factor form without looking ahead', function () {
    $team = Team::factory()->create();
    $targetOpponent = Team::factory()->create();

    createWnbaSignalGame(
        $team,
        Team::factory()->create(),
        '2026-07-01',
        '20:00:00',
        homeStats: ['field_goals_made' => 0, 'field_goals_attempted' => 60, 'three_point_made' => 0, 'free_throws_attempted' => 0, 'offensive_rebounds' => 0, 'turnovers' => 24, 'fouls' => 30, 'points' => 40, 'possessions' => 80],
        awayStats: ['defensive_rebounds' => 40, 'points' => 100],
    );

    foreach (range(2, 6) as $day) {
        createWnbaSignalGame(
            $team,
            Team::factory()->create(),
            sprintf('2026-07-%02d', $day),
            '20:00:00',
            homeStats: ['field_goals_made' => 30, 'field_goals_attempted' => 60, 'three_point_made' => 6, 'free_throws_attempted' => 15, 'offensive_rebounds' => 10, 'turnovers' => 12, 'fouls' => 20, 'points' => 80, 'possessions' => 80],
            awayStats: ['defensive_rebounds' => 30, 'points' => 75],
        );
    }

    createWnbaSignalGame(
        $team,
        Team::factory()->create(),
        '2026-07-07',
        '23:30:00',
        homeStats: ['field_goals_made' => 0, 'field_goals_attempted' => 80, 'three_point_made' => 0, 'free_throws_attempted' => 0, 'offensive_rebounds' => 0, 'turnovers' => 30, 'fouls' => 35, 'points' => 20, 'possessions' => 80],
        awayStats: ['defensive_rebounds' => 40, 'points' => 120],
    );

    $target = Game::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $targetOpponent->id,
        'season' => 2026,
        'season_type' => (string) config('wnba.season.types.regular', 2),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-07',
        'game_time' => '22:00:00',
    ]);

    $signals = app(WnbaPredictionSignalService::class)->forGame($target);

    expect($signals['home']['rolling']['last5'])
        ->sample_size->toBe(5)
        ->efg_pct->toBe(55.0)
        ->turnover_pct->toBe(15.0)
        ->offensive_rebound_pct->toBe(25.0)
        ->free_throw_rate->toBe(0.25)
        ->foul_rate->toBe(25.0)
        ->pace->toBe(80.0)
        ->net_rating->toBe(6.25)
        ->and($signals['home']['rolling']['last10'])
        ->sample_size->toBe(6)
        ->efg_pct->toBe(45.833)
        ->net_rating->toBe(-7.292);
});

/**
 * @param  array<string, mixed>  $homeStats
 * @param  array<string, mixed>  $awayStats
 */
function createWnbaSignalGame(
    Team $homeTeam,
    Team $awayTeam,
    string $date,
    string $time,
    array $homeStats = [],
    array $awayStats = [],
): Game {
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('wnba.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => $date,
        'game_time' => $time,
        'home_score' => $homeStats['points'] ?? 80,
        'away_score' => $awayStats['points'] ?? 75,
    ]);

    TeamStat::factory()->create(array_merge([
        'team_id' => $homeTeam->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'field_goals_made' => 30,
        'field_goals_attempted' => 60,
        'three_point_made' => 6,
        'free_throws_attempted' => 15,
        'offensive_rebounds' => 10,
        'defensive_rebounds' => 30,
        'turnovers' => 12,
        'fouls' => 20,
        'points' => 80,
        'possessions' => 80,
    ], $homeStats));

    TeamStat::factory()->create(array_merge([
        'team_id' => $awayTeam->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'field_goals_made' => 28,
        'field_goals_attempted' => 62,
        'three_point_made' => 7,
        'free_throws_attempted' => 12,
        'offensive_rebounds' => 8,
        'defensive_rebounds' => 30,
        'turnovers' => 13,
        'fouls' => 19,
        'points' => 75,
        'possessions' => 80,
    ], $awayStats));

    return $game;
}
