<?php

use App\Actions\CFB\GeneratePrediction;
use App\Actions\CFB\GradePredictions;
use App\Models\CFB\EloRating;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\GameWeather;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\Prediction;
use App\Models\CFB\PredictionCalibration;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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

it('applies bounded advanced cfb signal adjustments before final output caps', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.rating_consensus_spread_weight' => 0.10,
        'cfb.predictions.success_rate_spread_weight' => 10.0,
        'cfb.predictions.explosiveness_spread_weight' => 2.0,
        'cfb.predictions.havoc_spread_weight' => 5.0,
        'cfb.predictions.ol_qb_environment_spread_weight' => 1.0,
        'cfb.predictions.advanced_total_success_weight' => 0,
        'cfb.predictions.advanced_total_explosiveness_weight' => 0,
        'cfb.predictions.advanced_total_havoc_weight' => 0,
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
    createCfbTeamMetric($homeTeam, 2025, fpi: 0.0, wepaNet: 0.0, netRating: 0.0, extra: [
        'rating_consensus' => 20.0,
        'net_success_rate' => 0.12,
        'net_explosiveness' => 0.25,
        'net_havoc_rate' => 0.10,
        'offensive_line_rating' => 0.60,
        'qb_environment_rating' => 0.40,
    ]);
    createCfbTeamMetric($awayTeam, 2025, fpi: 0.0, wepaNet: 0.0, netRating: 0.0, extra: [
        'rating_consensus' => 0.0,
        'net_success_rate' => 0.02,
        'net_explosiveness' => 0.05,
        'net_havoc_rate' => 0.00,
        'offensive_line_rating' => -0.20,
        'qb_environment_rating' => -0.40,
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

    expect((float) $prediction->predicted_spread)->toBe(4.7)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_advanced_metric_layer.spread.rating_consensus.adjustment'))->toBe(2.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_advanced_metric_layer.spread.success_rate.adjustment'))->toBe(1.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_advanced_metric_layer.spread.explosiveness.adjustment'))->toBe(0.4)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_advanced_metric_layer.spread.havoc.adjustment'))->toBe(0.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_advanced_metric_layer.spread.ol_qb_environment.adjustment'))->toBe(0.8);
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

it('applies bounded coaching scheme and special teams preseason adjustments', function () {
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
        'cfb.predictions.preseason.coaching_scheme.points_per_score_gap' => 1.0,
        'cfb.predictions.preseason.coaching_scheme.max_adjustment' => 1.25,
        'cfb.predictions.preseason.coaching_scheme.total_points_per_score' => 1.0,
        'cfb.predictions.preseason.coaching_scheme.max_total_adjustment' => 1.5,
        'cfb.predictions.preseason.coaching_scheme.volatility_threshold' => 0.55,
        'cfb.predictions.preseason.coaching_scheme.confidence_penalty_per_volatile_side' => 1.5,
        'cfb.predictions.preseason.special_teams.spread_weight' => 0.2,
        'cfb.predictions.preseason.special_teams.max_adjustment' => 1.0,
        'cfb.predictions.preseason.special_teams.total_weight' => 0.05,
        'cfb.predictions.preseason.special_teams.max_total_adjustment' => 1.0,
        'cfb.predictions.preseason.special_teams.mismatch_threshold' => 4.0,
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
        'coaching_continuity_payload' => json_encode([
            'scheme_continuity_score' => 0.8,
            'scheme_change_score' => 0.2,
            'tempo_change_score' => 0.4,
        ]),
    ]);
    createCfbCanonicalPreseasonSignal($awayTeam, [
        'coaching_continuity_payload' => json_encode([
            'scheme_continuity_score' => -0.6,
            'scheme_change_score' => 0.8,
            'tempo_change_score' => 0.2,
        ]),
    ]);
    FpiRating::factory()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'week' => 1,
        'special_teams' => 4.0,
    ]);
    FpiRating::factory()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 1,
        'special_teams' => -1.0,
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

    expect((float) $prediction->predicted_spread)->toBe(2.3)
        ->and((float) $prediction->predicted_total)->toBe(52.8)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.coaching_scheme.spread_adjustment'))->toBe(1.25)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.coaching_scheme.total_adjustment'))->toBe(0.6)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.coaching_scheme.risk_flags'))->toContain('coaching_scheme_volatility')
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.special_teams.spread_adjustment'))->toBe(1.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.special_teams.total_adjustment'))->toBe(0.15)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.special_teams.risk_flags'))->toContain('special_teams_mismatch');
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
        'cfb.predictions.rating_consensus_spread_weight' => 0,
        'cfb.predictions.success_rate_spread_weight' => 0,
        'cfb.predictions.explosiveness_spread_weight' => 0,
        'cfb.predictions.havoc_spread_weight' => 0,
        'cfb.predictions.ol_qb_environment_spread_weight' => 0,
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

it('applies active adaptive calibration to future cfb predictions', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.rating_consensus_spread_weight' => 0,
        'cfb.predictions.success_rate_spread_weight' => 0,
        'cfb.predictions.explosiveness_spread_weight' => 0,
        'cfb.predictions.havoc_spread_weight' => 0,
        'cfb.predictions.ol_qb_environment_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.adaptive_calibration.enabled' => true,
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

    PredictionCalibration::query()->create([
        'season' => 2026,
        'training_from_week' => 0,
        'training_through_week' => 1,
        'games_count' => 12,
        'min_games' => 8,
        'learning_rate' => 0.250,
        'parameters' => [
            'week_buckets' => [
                'week_0_1' => [
                    'sample_size' => 12,
                    'spread_adjustment' => 2.5,
                    'total_adjustment' => -1.5,
                    'confidence_penalty' => 3.0,
                    'status' => 'active',
                ],
            ],
            'preseason_component_multipliers' => [],
        ],
        'metrics' => [],
        'is_active' => true,
        'generated_at' => now(),
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

    expect((float) $prediction->predicted_spread)->toBe(2.5)
        ->and((float) $prediction->predicted_total)->toBe(50.5)
        ->and((float) $prediction->confidence_score)->toBe(55.8)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.adaptive_week_calibration.spread_adjustment'))->toBe(2.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_preseason_layer.components.adaptive_week_calibration.confidence_penalty'))->toBe(3.0)
        ->and(data_get($snapshot->model_metadata, 'cfb_preseason_layer.adaptive_calibration.id'))->not->toBeNull();
});

it('weights cfb player availability by position and recent production', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.player_availability.enabled' => true,
        'cfb.predictions.player_availability.position_weights.QB' => 2.0,
        'cfb.predictions.player_availability.position_weights.WR' => 1.0,
        'cfb.predictions.player_availability.production_baselines.QB' => 10.0,
        'cfb.predictions.player_availability.production_baselines.WR' => 10.0,
        'cfb.predictions.player_availability.max_player_multiplier' => 3.0,
        'cfb.predictions.player_availability.min_player_multiplier' => 0.5,
        'cfb.predictions.injury_out_spread_penalty' => 0.50,
        'cfb.predictions.injury_out_total_penalty' => 0.30,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $homeQb = Player::query()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => 'cfb-home-qb-availability-test',
        'full_name' => 'Home QB',
        'position' => 'QB',
    ]);
    $awayReceiver = Player::query()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => 'cfb-away-wr-availability-test',
        'full_name' => 'Away WR',
        'position' => 'WR',
    ]);

    createCfbEloRating($homeTeam, 2025, 14, 1500);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $priorGame = Game::factory()->create([
        'season' => 2025,
        'week' => 14,
        'season_type' => 'regular',
        'game_date' => '2025-11-29 19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'neutral_site' => true,
    ]);

    DB::table('cfb_player_stats')->insert([
        'player_id' => $homeQb->id,
        'game_id' => $priorGame->id,
        'team_id' => $homeTeam->id,
        'passing_attempts' => 30,
        'passing_yards' => 250,
        'passing_touchdowns' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cfb_player_stats')->insert([
        'player_id' => $awayReceiver->id,
        'game_id' => $priorGame->id,
        'team_id' => $awayTeam->id,
        'receptions' => 1,
        'receiving_targets' => 2,
        'receiving_yards' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PlayerInjury::query()->create([
        'player_id' => $homeQb->id,
        'team_id' => $homeTeam->id,
        'injury_key' => 'home-qb-out',
        'status' => 'Out',
        'detail' => 'Shoulder',
        'injury_date' => '2026-08-20',
        'is_active' => true,
    ]);
    PlayerInjury::query()->create([
        'player_id' => $awayReceiver->id,
        'team_id' => $awayTeam->id,
        'injury_key' => 'away-wr-out',
        'status' => 'Out',
        'detail' => 'Ankle',
        'injury_date' => '2026-08-20',
        'is_active' => true,
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

    expect((float) $prediction->predicted_spread)->toBe(-2.6)
        ->and(data_get($snapshot->model_metadata, 'cfb_player_availability.applied'))->toBeTrue()
        ->and((float) data_get($snapshot->model_metadata, 'cfb_player_availability.home.out'))->toBe(5.7)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_player_availability.away.out'))->toBe(0.5)
        ->and(data_get($snapshot->model_metadata, 'cfb_player_availability.risk_flags'))->toContain('player_availability_impact');
});

it('applies stored cfb weather context to totals and snapshot metadata', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.game_context.enabled' => true,
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

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-05',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    GameWeather::query()->create([
        'game_id' => $game->id,
        'provider' => 'open_meteo',
        'observed_at' => '2026-09-05 19:00:00',
        'temperature_f' => 25,
        'wind_speed_mph' => 20,
        'wind_gust_mph' => 30,
        'precipitation_probability' => 70,
        'precipitation_inches' => 0.05,
        'humidity_percent' => 65,
        'condition_code' => '61',
        'is_indoor' => false,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam', 'weather']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(0.0)
        ->and((float) $prediction->predicted_total)->toBe(49.2)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_game_context.total_adjustment'))->toBe(-2.84)
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.weather.risk_flags'))->toContain('wind_total_suppression')
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.weather.risk_flags'))->toContain('weather_turnover_risk');
});

it('applies cfb schedule psychology context with home-margin sign convention', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => false,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.rating_consensus_spread_weight' => 0,
        'cfb.predictions.success_rate_spread_weight' => 0,
        'cfb.predictions.explosiveness_spread_weight' => 0,
        'cfb.predictions.havoc_spread_weight' => 0,
        'cfb.predictions.ol_qb_environment_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.game_context.enabled' => true,
    ]);

    $homeTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1600,
    ]);
    $awayTeam = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $awayPreviousOpponent = Team::factory()->create(['elo_rating' => 1450]);
    $homeNextOpponent = Team::factory()->create(['elo_rating' => 1750]);
    $awayNextOpponent = Team::factory()->create(['elo_rating' => 1750]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-06',
        'game_time' => '19:00:00',
        'home_team_id' => $awayPreviousOpponent->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 17,
        'away_score' => 24,
        'neutral_site' => false,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 2,
        'season_type' => 'regular',
        'game_date' => '2026-09-12',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 3,
        'season_type' => 'regular',
        'game_date' => '2026-09-19',
        'game_time' => '19:00:00',
        'home_team_id' => $homeNextOpponent->id,
        'away_team_id' => $homeTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);
    Game::factory()->create([
        'season' => 2026,
        'week' => 3,
        'season_type' => 'regular',
        'game_date' => '2026-09-19',
        'game_time' => '19:00:00',
        'home_team_id' => $awayNextOpponent->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) $prediction->predicted_spread)->toBe(12.9)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_game_context.spread_adjustment'))->toBe(0.5)
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.schedule.risk_flags'))->toContain('away_short_rest')
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.schedule.risk_flags'))->toContain('away_consecutive_road')
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.schedule.risk_flags'))->toContain('home_lookahead_spot')
        ->and(data_get($snapshot->model_metadata, 'cfb_game_context.schedule.risk_flags'))->toContain('away_lookahead_spot');
});

it('tracks cfb market movement consensus without flipping spread signs incorrectly', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.game_context.enabled' => false,
        'cfb.predictions.player_availability.enabled' => false,
        'cfb.predictions.market_movement.enabled' => true,
        'cfb.predictions.market_movement.confidence_boost_toward_model' => 1.5,
        'cfb.predictions.market_movement.book_disagreement_threshold' => 2.0,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));

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

    createCfbEloRating($homeTeam, 2025, 14, 1600);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-05 19:00:00',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    recordCfbSpreadSnapshot($game, '2026-08-20T12:00:00Z', [
        'draftkings' => -3.5,
        'fanduel' => -4.5,
    ]);
    recordCfbSpreadSnapshot($game, '2026-09-04T11:00:00Z', [
        'draftkings' => -6.0,
        'fanduel' => -7.0,
    ]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) data_get($snapshot->model_metadata, 'cfb_market_movement.open_bookmaker_home_line'))->toBe(-4.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.open_home_margin'))->toBe(4.0)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.current_bookmaker_home_line'))->toBe(-6.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.current_home_margin'))->toBe(6.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.line_movement_home_margin'))->toBe(2.5)
        ->and(data_get($snapshot->model_metadata, 'cfb_market_movement.line_moved_toward_model'))->toBeTrue()
        ->and(data_get($snapshot->model_metadata, 'cfb_market_movement.risk_flags'))->toContain('market_moved_toward_model')
        ->and((float) data_get($snapshot->outputs, 'bookmaker_home_spread'))->toBe(-6.5)
        ->and((float) data_get($snapshot->outputs, 'market_spread'))->toBe(6.5)
        ->and((float) $prediction->confidence_score)->toBeGreaterThan(50.0);

    Carbon::setTestNow();
});

it('enriches cfb market movement with closing line value when predictions are graded', function () {
    config([
        'cfb.predictions.use_previous_season_elo_fallback' => true,
        'cfb.predictions.previous_season_elo_regression_factor' => 0.0,
        'cfb.predictions.fpi_spread_weight' => 0,
        'cfb.predictions.wepa_spread_weight' => 0,
        'cfb.predictions.efficiency_spread_weight' => 0,
        'cfb.predictions.margin_calibration.enabled' => false,
        'cfb.predictions.preseason.enabled' => false,
        'cfb.predictions.week_calibration.enabled' => false,
        'cfb.predictions.game_context.enabled' => false,
        'cfb.predictions.player_availability.enabled' => false,
        'cfb.predictions.market_movement.enabled' => true,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));

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

    createCfbEloRating($homeTeam, 2025, 14, 1600);
    createCfbEloRating($awayTeam, 2025, 14, 1500);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-05 19:00:00',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => true,
    ]);

    recordCfbSpreadSnapshot($game, '2026-08-20T12:00:00Z', ['draftkings' => -4.0]);
    recordCfbSpreadSnapshot($game, '2026-09-04T11:00:00Z', ['draftkings' => -6.5]);

    app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']), false);

    recordCfbSpreadSnapshot($game, '2026-09-05T18:45:00Z', ['draftkings' => -7.5]);

    Carbon::setTestNow(Carbon::parse('2026-09-06 01:00:00'));
    $game->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 31,
        'away_score' => 20,
    ]);

    app(GradePredictions::class)->execute(2026);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = cfbPredictionSnapshot($prediction);

    expect((float) data_get($snapshot->model_metadata, 'cfb_market_movement.closing_bookmaker_home_line'))->toBe(-7.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.closing_home_margin'))->toBe(7.5)
        ->and((float) data_get($snapshot->model_metadata, 'cfb_market_movement.closing_line_value_points'))->toBe(1.0)
        ->and(data_get($snapshot->model_metadata, 'cfb_market_movement.closing_line_value_bucket'))->toBe('positive');

    Carbon::setTestNow();
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

/**
 * @param  array<string, float>  $bookmakerHomeLines
 */
function recordCfbSpreadSnapshot(Game $game, string $capturedAt, array $bookmakerHomeLines): void
{
    $homeName = (string) $game->homeTeam->school;
    $awayName = (string) $game->awayTeam->school;

    app(GameOddsSnapshotRecorder::class)->record(
        'cfb',
        $game,
        [
            'id' => 'cfb-'.$game->id.'-'.sha1($capturedAt),
            'commence_time' => '2026-09-05T19:00:00Z',
        ],
        [
            'home_team' => $homeName,
            'away_team' => $awayName,
            'bookmakers' => collect($bookmakerHomeLines)->map(
                fn (float $homeLine, string $bookmaker): array => [
                    'key' => $bookmaker,
                    'title' => ucfirst($bookmaker),
                    'markets' => [[
                        'key' => 'spreads',
                        'outcomes' => [
                            ['name' => $homeName, 'price' => -110, 'point' => $homeLine],
                            ['name' => $awayName, 'price' => -110, 'point' => -$homeLine],
                        ],
                    ]],
                ]
            )->values()->all(),
        ],
        Carbon::parse($capturedAt),
    );
}
