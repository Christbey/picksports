<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\User;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows MLB lineage, data readiness, weekly metrics, market results, and failures to admins', function () {
    $this->withoutVite();
    $admin = User::factory()->admin()->create();
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'walk_forward_training',
        modelVersion: 'mlb-xgboost-v1',
        featureVersion: 'mlb-pregame-core-v1',
        blendVersion: 'mlb-market-blend-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'mlb',
        'market_type' => 'win_probability',
        'model_type' => 'xgboost_classifier',
        'model_version' => 'mlb-xgboost-v1',
        'feature_version' => 'mlb-pregame-core-v1',
        'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => storage_path('app/ml/tests/mlb-model.ubj'),
        'artifact_hash' => str_repeat('b', 64),
        'status' => 'promoted',
        'promotion_decision' => [
            'promoted_markets' => ['win_probability'],
            'checks' => ['minimum_windows' => true],
        ],
        'promoted_at' => now()->subHour(),
    ]);
    $challengerRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'walk_forward_training',
        modelVersion: 'mlb-blend-v2',
        featureVersion: 'mlb-pregame-core-v1',
        blendVersion: 'mlb-market-blend-v2',
        status: 'completed',
        completedAt: now(),
    );
    $challenger = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $challengerRun->id,
        'sport' => 'mlb',
        'market_type' => 'multi_market',
        'model_type' => 'calibrated_blend',
        'model_version' => 'mlb-blend-v2',
        'feature_version' => 'mlb-pregame-core-v1',
        'dataset_hash' => str_repeat('c', 64),
        'artifact_path' => storage_path('app/ml/tests/mlb-blend.joblib'),
        'artifact_hash' => str_repeat('d', 64),
        'status' => 'challenger',
    ]);
    $home = Team::factory()->create(['abbreviation' => 'HOM']);
    $away = Team::factory()->create(['abbreviation' => 'AWY']);
    $completedGame = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-07-28',
        'game_time' => '19:10:00',
        'status' => 'STATUS_FINAL',
        'home_score' => 6,
        'away_score' => 3,
    ]);
    $priorSeasonGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2025-07-28',
        'game_time' => '19:10:00',
        'status' => 'STATUS_FINAL',
        'home_score' => 4,
        'away_score' => 2,
    ]);
    $upcomingWithCoverage = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-07-30',
        'game_time' => '19:10:00',
        'status' => 'STATUS_SCHEDULED',
        'probable_home_pitcher_espn_id' => 'pitcher-home',
        'probable_away_pitcher_espn_id' => 'pitcher-away',
    ]);
    Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'game_date' => '2026-07-31',
        'game_time' => '18:40:00',
        'status' => 'STATUS_SCHEDULED',
        'probable_home_pitcher_espn_id' => null,
        'probable_away_pitcher_espn_id' => null,
    ]);
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 500,
        'game_id' => $upcomingWithCoverage->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $trainingRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'mlb-pregame-core-v1',
        'blend_version' => 'baseline-v1',
        'features' => ['home_elo' => 1520],
        'outputs' => [],
        'feature_hash' => str_repeat('0', 64),
        'generated_at' => '2026-07-29 10:00:00',
        'game_start_at' => '2026-07-30 19:10:00',
        'features_available_at' => '2026-07-29 10:00:00',
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 499,
        'game_id' => $completedGame->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $trainingRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'mlb-trusted-core-v1',
        'blend_version' => 'baseline-v1',
        'features' => ['home_elo' => 1510],
        'outputs' => [],
        'feature_hash' => str_repeat('9', 64),
        'generated_at' => '2026-07-28 16:00:00',
        'game_start_at' => '2026-07-28 19:10:00',
        'features_available_at' => '2026-07-28 16:00:00',
        'pregame_safe' => true,
        'availability_status' => 'verified_reconstruction',
    ]);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $upcomingWithCoverage->id,
        'odds_api_event_id' => 'odds-event-1',
        'bookmaker_key' => 'draftkings',
        'bookmaker_title' => 'DraftKings',
        'source' => 'odds_api',
        'commence_time' => '2026-07-30 19:10:00',
        'captured_at' => now()->subMinutes(20),
        'payload_hash' => str_repeat('e', 64),
        'odds_data' => [],
        'market_context' => [],
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $upcomingWithCoverage->id,
        'source' => 'odds_api',
        'bookmaker_key' => 'draftkings',
        'bookmaker_title' => 'DraftKings',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => -120,
        'implied_probability' => 0.545455,
        'no_vig_probability' => 0.52,
        'commence_time' => '2026-07-30 19:10:00',
        'captured_at' => now()->subMinutes(20),
        'is_pregame' => true,
        'quote_hash' => str_repeat('f', 64),
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-v1',
        status: 'completed',
        completedAt: now()->subHours(12),
    );
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 501,
        'game_id' => $completedGame->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $trainingRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'mlb-pregame-core-v1',
        'blend_version' => 'baseline-v1',
        'features' => ['home_elo' => 1530],
        'outputs' => [
            'baseline_win_probability' => 0.55,
            'challenger_win_probability' => 0.65,
            'baseline_predicted_spread' => 99.0,
            'challenger_predicted_spread' => 99.0,
            'baseline_predicted_total' => 99.0,
            'challenger_predicted_total' => 99.0,
        ],
        'feature_hash' => str_repeat('1', 64),
        'generated_at' => '2026-07-28 16:00:00',
        'game_start_at' => '2026-07-28 19:10:00',
        'features_available_at' => '2026-07-28 16:00:00',
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $completedGame->id,
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 501,
        'market_type' => 'win_probability',
        'baseline_output' => 0.55,
        'challenger_output' => 0.65,
        'output_delta' => 0.10,
        'status' => 'promoted_shadow',
        'explanation' => [
            'public_output_changed' => false,
            'baseline_outputs' => [
                'win_probability' => 0.55,
                'predicted_spread' => 1.5,
                'predicted_total' => 8.0,
            ],
            'challenger_outputs' => [
                'win_probability' => 0.65,
                'predicted_spread' => 2.5,
                'predicted_total' => 8.5,
            ],
        ],
        'generated_at' => '2026-07-28 16:00:00',
    ]);
    $settledDecision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'shadow_model_output_id' => $shadow->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => $shadow->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $completedGame->id,
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 501,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => -120,
        'no_vig_probability' => 0.52,
        'model_probability' => 0.65,
        'edge' => 0.13,
        'status' => 'tracking_bet',
        'recommendation_label' => 'shadow_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => true,
        'pregame_safe' => true,
        'eligibility_reasons' => [],
        'decided_at' => '2026-07-28 16:00:00',
        'locked_at' => '2026-07-28 16:00:00',
        'game_start_at' => '2026-07-28 19:10:00',
        'decision_hash' => str_repeat('2', 64),
    ]);
    BetSettlement::query()->create([
        'bet_decision_id' => $settledDecision->id,
        'result_status' => 'win',
        'result_value' => 3,
        'profit_units' => 0.8,
        'clv' => 0.025,
        'graded_at' => now()->subHours(2),
        'settled_at' => now()->subHours(2),
    ]);
    BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'shadow_model_output_id' => $shadow->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => $shadow->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $completedGame->id,
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => 501,
        'market_type' => 'total',
        'market_key' => 'totals',
        'side' => 'over',
        'line' => 9.0,
        'price' => -110,
        'status' => 'shadow_no_bet',
        'recommendation_label' => 'no_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => false,
        'pregame_safe' => true,
        'eligibility_reasons' => ['market_model_not_promoted'],
        'decided_at' => '2026-07-28 16:01:00',
        'locked_at' => '2026-07-28 16:01:00',
        'game_start_at' => '2026-07-28 19:10:00',
        'decision_hash' => str_repeat('3', 64),
    ]);
    BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => 999,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $priorSeasonGame->id,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'status' => 'shadow_no_bet',
        'recommendation_label' => 'no_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => false,
        'pregame_safe' => true,
        'eligibility_reasons' => ['prior_season_reason'],
        'decided_at' => '2025-07-28 16:00:00',
        'locked_at' => '2025-07-28 16:00:00',
        'game_start_at' => '2025-07-28 19:10:00',
        'decision_hash' => str_repeat('8', 64),
    ]);
    ModelRun::query()->create([
        'id' => (string) Str::uuid(),
        'sport' => 'mlb',
        'run_type' => 'shadow_inference',
        'model_version' => 'mlb-blend-v2',
        'feature_version' => 'mlb-pregame-core-v1',
        'blend_version' => 'shadow-v2',
        'config_hash' => str_repeat('4', 64),
        'status' => 'failed',
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(9),
        'metadata' => ['error' => 'Inference process timed out.'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.mlb-model-monitoring'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/MlbModelMonitoring')
            ->where('artifact.id', $artifact->id)
            ->where('lineage.active.id', $artifact->id)
            ->where('lineage.challenger.id', $challenger->id)
            ->where('data_health.pregame_safe_snapshots', 1)
            ->where('data_health.upcoming_games', 2)
            ->where('data_health.probable_pitcher_coverage', 0.5)
            ->where('data_health.market_quote_coverage', 0.5)
            ->where('summary.shadow_observations', 1)
            ->where('summary.decisions', 2)
            ->where('summary.settled_decisions', 1)
            ->where('summary.roi', 0.8)
            ->where('summary.average_clv', 0.025)
            ->where('summary.baseline_brier', 0.2025)
            ->where('summary.challenger_brier', 0.1225)
            ->where('weekly_performance.0.games', 1)
            ->where('weekly_performance.0.baseline_margin_mae', 1.5)
            ->where('weekly_performance.0.challenger_margin_mae', 0.5)
            ->where('weekly_performance.0.baseline_total_mae', 1)
            ->where('weekly_performance.0.challenger_total_mae', 0.5)
            ->where('market_performance.0.market', 'Moneyline')
            ->where('market_performance.1.market', 'Total')
            ->where('no_bet_reasons.0.reason', 'market_model_not_promoted')
            ->where('inference_failures.0.error', 'Inference process timed out.')
            ->where('observations.0.matchup', 'AWY @ HOM')
            ->where('observations.0.pregame_safe', true)
        );
});

it('protects the MLB model monitor with authentication and admin authorization', function () {
    $this->get('/admin/mlb-model-monitoring')
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get('/admin/mlb-model-monitoring')
        ->assertForbidden();

    $this->withoutVite();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/mlb-model-monitoring')
        ->assertOk();
});
