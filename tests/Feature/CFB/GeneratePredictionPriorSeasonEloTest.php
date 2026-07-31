<?php

use App\Actions\CFB\GeneratePrediction;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use Illuminate\Support\Facades\Schema;

uses()->group('cfb', 'predictions');

it('uses regressed prior season elo for week zero predictions before current season ratings exist', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_fallback_through_week' => 4,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.30,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1400,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1700,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1600);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $prediction->home_elo)->toBe(1570.0)
        ->and((float) $prediction->away_elo)->toBe(1500.0)
        ->and((float) $prediction->predicted_spread)->toBe(10.0);
});

it('uses same season elo history before falling back to prior season elo', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_fallback_through_week' => 4,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.30,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1400,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1700,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1600);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbEloRating($homeTeam, 2026, 0, 1588, '2026-08-30');
    createCfbEloRating($awayTeam, 2026, 0, 1492, '2026-08-30');

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-05 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $prediction->home_elo)->toBe(1588.0)
        ->and((float) $prediction->away_elo)->toBe(1492.0)
        ->and((float) $prediction->predicted_spread)->toBe(12.1);
});

it('generates week zero predictions from the command week filter', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.30,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1600);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $this->artisan('cfb:generate-predictions', [
        '--season' => 2026,
        '--week' => 0,
    ])->assertSuccessful();

    expect(Prediction::query()->where('game_id', $game->id)->exists())->toBeTrue();
});

it('expands low and mid cfb spreads when quality signals agree with the projected side', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.30,
        'cfb.predictions.margin_calibration.enabled' => true,
        'cfb.predictions.margin_calibration.min_non_elo_signals' => 2,
        'cfb.predictions.margin_calibration.mid_band_factor' => 1.45,
        'cfb.predictions.margin_calibration.max_bonus_points' => 6.0,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1550);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbTeamMetric($homeTeam, 2025, fpi: 6.0, wepaNet: 0.6, netRating: 8.0);
    createCfbTeamMetric($awayTeam, 2025, fpi: 0.0, wepaNet: 0.0, netRating: 0.0);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $prediction->predicted_spread)->toBe(16.4);
});

it('does not expand cfb spreads when a meaningful quality signal opposes the side', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.30,
        'cfb.predictions.margin_calibration.enabled' => true,
        'cfb.predictions.margin_calibration.min_non_elo_signals' => 2,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1550);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbTeamMetric($homeTeam, 2025, fpi: 0.0, wepaNet: 0.6, netRating: 8.0);
    createCfbTeamMetric($awayTeam, 2025, fpi: 6.0, wepaNet: 0.0, netRating: 0.0);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $prediction->predicted_spread)->toBe(9.1);
});

function createCfbEloRating(Team $team, int $season, int $week, float $elo, ?string $date = null): void
{
    $attributes = [
        'team_id' => $team->id,
        'season' => $season,
        'week' => $week,
        'season_type' => 'regular',
        'elo_rating' => $elo,
    ];

    if (Schema::hasColumn((new EloRating)->getTable(), 'date')) {
        $attributes['date'] = $date ?? "{$season}-12-15";
    }

    if (Schema::hasColumn((new EloRating)->getTable(), 'elo_change')) {
        $attributes['elo_change'] = 0.0;
    }

    EloRating::query()->create($attributes);
}

function createCfbTeamMetric(Team $team, int $season, float $fpi, float $wepaNet, float $netRating): void
{
    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => $season,
        'wins' => 8,
        'losses' => 4,
        'fpi' => $fpi,
        'cfbd_wepa_net' => $wepaNet,
        'net_rating' => $netRating,
        'offensive_rating' => 32.0 + max(0, $netRating),
        'defensive_rating' => 24.0,
        'points_per_game' => 32.0,
        'points_allowed_per_game' => 24.0,
        'calculation_date' => "{$season}-12-15",
    ]);
}
