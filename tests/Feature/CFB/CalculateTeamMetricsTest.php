<?php

use App\Actions\CFB\CalculateTeamMetrics;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamStat;
use App\Services\CollegeFootballData\CollegeFootballDataService;

uses()->group('cfb', 'team-metrics');

beforeEach(function () {
    $this->team = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $this->opponent = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1450,
    ]);
});

it('calculates team metrics from complete final game stats', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent->id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::query()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'total_yards' => 420,
        'passing_yards' => 260,
        'rushing_yards' => 160,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    TeamStat::query()->create([
        'team_id' => $this->opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'total_yards' => 300,
        'passing_yards' => 190,
        'rushing_yards' => 110,
        'interceptions' => 1,
        'fumbles_lost' => 1,
    ]);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2026);

    expect($metric)->not->toBeNull()
        ->team_id->toBe($this->team->id)
        ->season->toBe(2026)
        ->offensive_rating->toBe('31.0')
        ->defensive_rating->toBe('17.0')
        ->yards_per_game->toBe('420.0')
        ->yards_allowed_per_game->toBe('300.0');
});

it('does not calculate metrics when completed game stats are incomplete', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent->id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::query()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'total_yards' => 420,
    ]);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2026);

    expect($metric)->toBeNull()
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2026)->exists())->toBeFalse();
});

it('persists advanced cfb signal metrics from cfbd payloads', function () {
    $this->team->forceFill(['school' => 'Georgia', 'cfbd_team_id' => 61, 'elo_rating' => 1625])->save();

    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2026)->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')
        ->once()
        ->with(2026, null, null, true)
        ->andReturn([
            [
                'team' => 'Georgia',
                'offense' => [
                    'successRate' => 0.49,
                    'explosiveness' => 1.48,
                    'havoc' => ['total' => 0.12],
                    'lineYards' => 3.25,
                    'stuffRate' => 0.14,
                    'sackRate' => 0.04,
                ],
                'defense' => [
                    'successRate' => 0.36,
                    'explosiveness' => 1.11,
                    'havoc' => ['total' => 0.22],
                ],
            ],
        ]);

    $this->app->instance(CollegeFootballDataService::class, $service);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team->fresh(), 2026);

    expect($metric)->not->toBeNull()
        ->and((float) $metric->offensive_success_rate)->toBe(0.49)
        ->and((float) $metric->defensive_success_rate)->toBe(0.36)
        ->and((float) $metric->net_success_rate)->toBe(0.13)
        ->and((float) $metric->net_explosiveness)->toBe(0.37)
        ->and((float) $metric->net_havoc_rate)->toBe(0.10)
        ->and((float) $metric->offensive_line_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->qb_environment_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->defensive_front_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->rating_consensus)->toBeGreaterThan(0.0)
        ->and($metric->cfbd_advanced_payload)->toHaveKey('offense');
});
