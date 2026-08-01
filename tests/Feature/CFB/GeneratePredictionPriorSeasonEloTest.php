<?php

use App\Actions\CFB\GeneratePrediction;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        'cfb.predictions.preseason.enabled' => false,
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
        'cfb.predictions.preseason.enabled' => false,
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
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
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
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
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

it('clamps final cfb outputs after shared context adjustments', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_fallback_through_week' => 6,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.max_spread' => 40,
        'cfb.predictions.min_spread' => -40,
        'cfb.predictions.max_total' => 88,
        'cfb.predictions.min_total' => 28,
        'cfb.predictions.preseason.enabled' => false,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 2100);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbTeamMetric($homeTeam, 2025, fpi: 0.0, wepaNet: 0.0, netRating: 0.0, extra: [
        'points_per_game' => 100.0,
        'points_allowed_per_game' => 100.0,
        'recent_form_rating' => 100.0,
        'injury_adjusted_team_rating' => 2200.0,
    ]);
    createCfbTeamMetric($awayTeam, 2025, fpi: 0.0, wepaNet: 0.0, netRating: 0.0, extra: [
        'points_per_game' => 100.0,
        'points_allowed_per_game' => 100.0,
        'recent_form_rating' => 100.0,
        'injury_adjusted_team_rating' => 1500.0,
    ]);

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

    expect((float) $prediction->predicted_spread)->toBe(40.0)
        ->and((float) $prediction->predicted_total)->toBe(88.0);
});

it('applies configurable preseason signals for weeks zero through four', function () {
    $signalTable = 'cfb_preseason_team_signals_feature_test';
    createCfbPreseasonSignalTable($signalTable);

    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => true,
        'cfb.predictions.preseason.signal_table' => $signalTable,
        'cfb.predictions.preseason.composite.power_rating_weight' => 0.10,
        'cfb.predictions.preseason.composite.fpi_weight' => 0.0,
        'cfb.predictions.preseason.composite.net_rating_weight' => 0.0,
        'cfb.predictions.preseason.market_guardrail.enabled' => false,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1500);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbPreseasonSignal($signalTable, $homeTeam, [
        'power_rating' => 20,
        'returning_production_percent' => 80,
        'qb_status' => 'returning_starter',
        'transfer_portal_net_score' => 40,
        'coaching_continuity' => 'stable',
    ]);
    createCfbPreseasonSignal($signalTable, $awayTeam, [
        'power_rating' => 10,
        'returning_production_percent' => 55,
        'qb_status' => 'first_time_starter',
        'transfer_portal_net_score' => -20,
        'coaching_continuity' => 'new_head_coach',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(7.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.spread_adjustment'))->toBe(7.5)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.risk_flags'))->toContain('qb_continuity_uncertainty')
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.risk_flags'))->toContain('coaching_staff_change');
});

it('uses synced canonical preseason signal columns in early season predictions', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => true,
        'cfb.predictions.preseason.market_guardrail.enabled' => false,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1500);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbCanonicalPreseasonSignal($homeTeam, [
        'returning_percent_ppa' => 0.800,
        'talent_composite' => 900.000,
        'recruiting_points' => 315.000,
        'recruiting_rank' => 2,
        'qb_continuity_classification' => 'returning_starter',
        'transfer_net_value' => 2.000,
        'transfer_qb_net_value' => 1.000,
        'coordinator_continuity_score' => 1.000,
    ]);
    createCfbCanonicalPreseasonSignal($awayTeam, [
        'returning_percent_ppa' => 0.500,
        'talent_composite' => 650.000,
        'recruiting_points' => 260.000,
        'recruiting_rank' => 25,
        'qb_continuity_classification' => 'first_time_starter',
        'transfer_net_value' => -1.000,
        'new_head_coach' => true,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(8.2)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.returning_production.spread_adjustment'))->toBe(2.4)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.talent_recruiting.spread_adjustment'))->toBe(0.772)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.transfer_portal.spread_adjustment'))->toBe(2.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.qb_continuity.spread_adjustment'))->toBe(2.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.coaching_continuity.spread_adjustment'))->toBe(1.0);
});

it('converts sportsbook home lines to home-margin convention for early market guardrails', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => true,
        'cfb.predictions.preseason.market_guardrail.enabled' => true,
        'cfb.predictions.preseason.market_guardrail.large_disagreement_threshold' => 10.0,
        'cfb.predictions.preseason.market_guardrail.required_aligned_signals' => 3,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'school' => 'Home State',
        'abbreviation' => 'HST',
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'school' => 'Away Tech',
        'abbreviation' => 'AT',
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1700);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-05 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Home State Hawks', 'point' => 3.0],
                        ['name' => 'Away Tech Wolves', 'point' => -3.0],
                    ],
                ]],
            ]],
        ],
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(16.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.market.bookmaker_home_line'))->toBe(3.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.market.market_home_margin'))->toBe(-3.0)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.market.risk_flags'))->toContain('market_disagreement_watchlist')
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.market.confidence_penalty'))->toBe(12.0);
});

it('applies no-op-by-default week bucket calibration hooks when configured', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => true,
        'cfb.predictions.week_calibration.buckets.week_5_8.spread_multiplier' => 0.5,
        'cfb.predictions.week_calibration.buckets.week_5_8.spread_adjustment' => 1.0,
        'cfb.predictions.week_calibration.buckets.week_5_8.confidence_penalty' => 4.0,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1700);
    createCfbEloRating($awayTeam, 2025, 14, 1500);
    createCfbEloRating($homeTeam, 2026, 5, 1700, '2026-10-01');
    createCfbEloRating($awayTeam, 2026, 5, 1500, '2026-10-01');

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 6,
        'season_type' => 'regular',
        'game_date' => '2026-10-10 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(9.0)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.week_bucket'))->toBe('week_5_8')
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.week_calibration.confidence_penalty'))->toBe(4.0);
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

function createCfbTeamMetric(Team $team, int $season, float $fpi, float $wepaNet, float $netRating, array $extra = []): void
{
    TeamMetric::query()->create(array_merge([
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
    ], $extra));
}

function createCfbPreseasonSignalTable(string $table): void
{
    if (Schema::hasTable($table)) {
        DB::table($table)->truncate();

        return;
    }

    Schema::create($table, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('team_id');
        $table->integer('season');
        $table->decimal('power_rating', 8, 3)->nullable();
        $table->decimal('returning_production_percent', 8, 3)->nullable();
        $table->string('qb_status')->nullable();
        $table->decimal('transfer_portal_net_score', 8, 3)->nullable();
        $table->string('coaching_continuity')->nullable();
        $table->json('payload')->nullable();
        $table->timestamps();
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createCfbPreseasonSignal(string $table, Team $team, array $attributes): void
{
    DB::table($table)->insert(array_merge([
        'team_id' => $team->id,
        'season' => 2026,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createCfbCanonicalPreseasonSignal(Team $team, array $attributes): void
{
    DB::table('cfb_preseason_team_signals')->insert(array_merge([
        'team_id' => $team->id,
        'season' => 2026,
        'incoming_transfer_count' => 0,
        'outgoing_transfer_count' => 0,
        'incoming_transfer_value' => 0,
        'outgoing_transfer_value' => 0,
        'transfer_net_value' => 0,
        'transfer_qb_net_value' => 0,
        'transfer_ol_net_value' => 0,
        'transfer_dl_net_value' => 0,
        'transfer_wr_net_value' => 0,
        'transfer_cb_net_value' => 0,
        'qb_continuity_classification' => 'unknown',
        'data_quality_status' => 'partial',
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function cfbPredictionSnapshot(Prediction $prediction): PredictionFeatureSnapshot
{
    return PredictionFeatureSnapshot::query()
        ->where('sport', 'cfb')
        ->where('prediction_table', $prediction->getTable())
        ->where('prediction_id', $prediction->id)
        ->latest('id')
        ->firstOrFail();
}
