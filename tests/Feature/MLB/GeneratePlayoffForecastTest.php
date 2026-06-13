<?php

use App\Actions\MLB\GeneratePlayoffForecast;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\MLB\TeamStat;

it('uses metric records when generating MLB futures forecasts', function () {
    $strongTeam = Team::factory()->create([
        'abbreviation' => 'LAD',
        'league' => 'National',
        'elo_rating' => 1500,
    ]);
    $weakTeam = Team::factory()->create([
        'abbreviation' => 'COL',
        'league' => 'National',
        'elo_rating' => 1500,
    ]);

    createMlbForecastMetric($strongTeam, [
        'wins' => 90,
        'losses' => 40,
    ]);
    createMlbForecastMetric($weakTeam, [
        'wins' => 40,
        'losses' => 90,
    ]);

    $forecasts = (new GeneratePlayoffForecast)->execute(2026);

    expect((int) $forecasts->firstWhere('team_id', $strongTeam->id)->league_rank)->toBe(1)
        ->and((int) $forecasts->firstWhere('team_id', $weakTeam->id)->league_rank)->toBe(2);
});

it('falls back to team stat runs instead of stale game scores for MLB futures records', function () {
    $winner = Team::factory()->create([
        'abbreviation' => 'NYM',
        'league' => 'National',
        'elo_rating' => 1500,
    ]);
    $loser = Team::factory()->create([
        'abbreviation' => 'SD',
        'league' => 'National',
        'elo_rating' => 1500,
    ]);

    createMlbForecastMetric($winner);
    createMlbForecastMetric($loser);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $winner->id,
        'away_team_id' => $loser->id,
        'home_score' => 0,
        'away_score' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $winner->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'runs' => 6,
    ]);
    TeamStat::factory()->create([
        'team_id' => $loser->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'runs' => 2,
    ]);

    $forecasts = (new GeneratePlayoffForecast)->execute(2026);

    expect((int) $forecasts->firstWhere('team_id', $winner->id)->league_rank)->toBe(1)
        ->and((int) $forecasts->firstWhere('team_id', $loser->id)->league_rank)->toBe(2);
});

it('resolves MLB futures leagues from canonical alignment when team rows are blank', function () {
    $braves = Team::factory()->create([
        'abbreviation' => 'ATL',
        'league' => null,
        'division' => null,
        'elo_rating' => 1500,
    ]);
    $yankees = Team::factory()->create([
        'abbreviation' => 'NYY',
        'league' => null,
        'division' => null,
        'elo_rating' => 1500,
    ]);

    createMlbForecastMetric($braves, ['wins' => 70, 'losses' => 50]);
    createMlbForecastMetric($yankees, ['wins' => 70, 'losses' => 50]);

    $forecasts = (new GeneratePlayoffForecast)->execute(2026);

    expect($forecasts->firstWhere('team_id', $braves->id)->league)->toBe('National League')
        ->and($forecasts->firstWhere('team_id', $yankees->id)->league)->toBe('American League')
        ->and($forecasts->pluck('league')->contains('Unknown'))->toBeFalse();
});

it('generates ordered MLB stage probabilities from the simulated playoff bracket', function () {
    config(['mlb.playoff_forecast.simulations' => 750]);

    foreach ([
        ['NYY', 94, 68],
        ['BAL', 88, 74],
        ['CLE', 86, 76],
        ['DET', 82, 80],
        ['SEA', 91, 71],
        ['TEX', 84, 78],
        ['ATL', 96, 66],
        ['NYM', 85, 77],
        ['MIL', 90, 72],
        ['STL', 83, 79],
        ['LAD', 95, 67],
        ['SD', 87, 75],
    ] as [$abbreviation, $wins, $losses]) {
        $team = Team::factory()->create([
            'abbreviation' => $abbreviation,
            'league' => null,
            'division' => null,
            'elo_rating' => 1500,
        ]);

        createMlbForecastMetric($team, [
            'wins' => $wins,
            'losses' => $losses,
            'offensive_rating' => 100 + (($wins - 81) * 1.5),
            'pitching_rating' => 100 + (($wins - 81) * 1.2),
        ]);
    }

    $forecasts = (new GeneratePlayoffForecast)->execute(2026);

    expect((float) $forecasts->sum('champion_probability'))->toBeGreaterThan(0.99)
        ->and((float) $forecasts->sum('champion_probability'))->toBeLessThan(1.01);

    foreach ($forecasts as $forecast) {
        expect((float) $forecast->playoff_make_probability)->toBeGreaterThanOrEqual((float) $forecast->division_series_probability)
            ->and((float) $forecast->division_series_probability)->toBeGreaterThanOrEqual((float) $forecast->league_championship_series_probability)
            ->and((float) $forecast->league_championship_series_probability)->toBeGreaterThanOrEqual((float) $forecast->pennant_probability)
            ->and((float) $forecast->pennant_probability)->toBe((float) $forecast->world_series_probability)
            ->and((float) $forecast->pennant_probability)->toBeGreaterThanOrEqual((float) $forecast->champion_probability)
            ->and((float) $forecast->playoff_make_probability)->toBeGreaterThanOrEqual((float) $forecast->division_win_probability);
    }
});

function createMlbForecastMetric(Team $team, array $overrides = []): TeamMetric
{
    return TeamMetric::query()->create(array_merge([
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => null,
        'losses' => null,
        'offensive_rating' => 100,
        'pitching_rating' => 100,
        'defensive_rating' => 100,
        'runs_per_game' => 4.5,
        'runs_allowed_per_game' => 4.5,
        'run_differential_per_game' => 0,
        'strength_of_schedule' => 1500,
        'calculation_date' => now()->toDateString(),
    ], $overrides));
}
