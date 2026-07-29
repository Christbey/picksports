<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\User;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('shows nfl artifact lineage, shadow decisions, and settled performance to admins', function () {
    $this->withoutVite();
    $admin = User::factory()->admin()->create();
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'profile_validation',
        modelVersion: 'nfl-full-historical-profile-v1',
        featureVersion: 'nfl-pregame-core-v1',
        blendVersion: 'full-historical-shadow-v1',
        status: 'completed',
        completedAt: now(),
    );
    $reportPath = storage_path('app/ml/tests/nfl-monitoring-report.json');
    File::ensureDirectoryExists(dirname($reportPath));
    File::put($reportPath, json_encode([
        'summary' => [
            'window_count' => 3,
            'challenger_better_window_count' => 3,
            'avg_brier_delta' => -0.02,
            'avg_log_loss_delta' => -0.03,
        ],
        'windows' => [
            [
                'evaluation_season' => 2023,
                'games' => 272,
                'baseline_brier' => 0.24,
                'challenger_brier' => 0.22,
                'brier_delta' => -0.02,
                'log_loss_delta' => -0.03,
                'spread_mae_delta' => -0.5,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'nfl',
        'market_type' => 'win_probability',
        'model_type' => 'nfl_full_historical_profile',
        'model_version' => 'nfl-full-historical-profile-v1',
        'feature_version' => 'nfl-pregame-core-v1',
        'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => storage_path('app/ml/tests/nfl-monitoring-artifact.json'),
        'artifact_hash' => str_repeat('b', 64),
        'status' => 'promoted',
        'evaluation_report_path' => $reportPath,
        'evaluation_report_hash' => hash_file('sha256', $reportPath),
        'promotion_decision' => [
            'checks' => [
                'minimum_windows' => true,
                'better_window_rate' => true,
                'average_primary_metric' => true,
                'average_secondary_metric' => true,
            ],
        ],
        'promoted_at' => now()->subDay(),
    ]);
    $home = Team::factory()->create(['abbreviation' => 'HOM']);
    $away = Team::factory()->create(['abbreviation' => 'AWY']);
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-01-10',
        'game_time' => '19:20:00',
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
        'season' => 2025,
        'week' => 18,
    ]);
    $generatedAt = Carbon::parse('2026-01-10 16:00:00');
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: $generatedAt,
    );
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 901,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $trainingRun->id,
        'model_version' => 'nfl-historical-elo-v2',
        'feature_version' => 'nfl-pregame-core-v1',
        'blend_version' => 'nfl-multi-signal-v1',
        'features' => ['home_elo' => 1540],
        'outputs' => [
            'win_probability' => 0.62,
            'predicted_spread' => 3.5,
            'predicted_total' => 45.0,
            'market_spread' => 2.5,
            'market_total' => 44.0,
            'baseline_win_probability' => 0.55,
            'challenger_win_probability' => 0.62,
            'baseline_predicted_spread' => 2.0,
            'challenger_predicted_spread' => 3.5,
            'baseline_predicted_total' => 44.0,
            'challenger_predicted_total' => 45.0,
            'active_source' => 'baseline',
        ],
        'feature_hash' => str_repeat('c', 64),
        'model_metadata' => [
            'analysis_layer' => [
                'reason_codes' => ['qb_form_home_edge'],
                'risk_flags' => ['low_model_confidence'],
                'reason_code_metadata' => [],
                'bet_rule_evaluation' => [
                    'matched_rules' => [[
                        'name' => 'moneyline_value_play',
                        'label' => 'Moneyline Value Play',
                        'action' => 'play',
                        'market' => 'winner',
                    ]],
                    'pass_rules' => ['pass_low_edge'],
                ],
                'validated_signals' => [[
                    'name' => 'elo_market_alignment',
                    'label' => 'Elo + Market Alignment',
                    'market' => 'winner',
                    'tier' => 'strong',
                ]],
            ],
        ],
        'generated_at' => $generatedAt,
        'game_start_at' => Carbon::parse('2026-01-10 19:20:00'),
        'features_available_at' => $generatedAt,
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 901,
        'market_type' => 'win_probability',
        'baseline_output' => 0.55,
        'challenger_output' => 0.62,
        'output_delta' => 0.07,
        'status' => 'promoted_shadow',
        'explanation' => ['public_output_changed' => false],
        'generated_at' => $generatedAt,
    ]);
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'shadow_model_output_id' => $shadow->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => $shadow->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 901,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 120,
        'no_vig_probability' => 0.50,
        'model_probability' => 0.62,
        'edge' => 0.12,
        'status' => 'tracking_bet',
        'recommendation_label' => 'shadow_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => true,
        'pregame_safe' => true,
        'eligibility_reasons' => [],
        'reason_codes' => ['promoted_model_edge'],
        'decided_at' => $generatedAt,
        'locked_at' => $generatedAt,
        'game_start_at' => Carbon::parse('2026-01-10 19:20:00'),
        'decision_hash' => str_repeat('d', 64),
    ]);
    BetSettlement::query()->create([
        'bet_decision_id' => $decision->id,
        'result_status' => 'win',
        'result_value' => 7,
        'profit_units' => 1.2,
        'clv' => 0.03,
        'graded_at' => now(),
        'settled_at' => now(),
        'metadata' => ['shadow_profit_units' => 1.2],
    ]);
    app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    NflSignalObservation::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->each(fn (NflSignalObservation $observation) => app(NflSignalGradingService::class)->grade($observation));

    $this->actingAs($admin)
        ->get(route('admin.nfl-model-monitoring'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/NflModelMonitoring')
            ->where('artifact.id', $artifact->id)
            ->where('artifact.config_hash', $trainingRun->config_hash)
            ->where('artifact.public_output_changed', false)
            ->where('summary.shadow_observations', 1)
            ->where('summary.decisions', 1)
            ->where('summary.tracking_bets', 1)
            ->where('summary.settled_decisions', 1)
            ->where('summary.actual_roi', 1.2)
            ->where('summary.challenger_brier', 0.1444)
            ->where('observations.0.matchup', 'AWY @ HOM')
            ->where('observations.0.active_source', 'baseline')
            ->where('observations.0.decision.is_public', false)
            ->where('observations.0.snapshot.pregame_safe', true)
            ->where('evaluation_windows.0.evaluation_season', 2023)
            ->has('signal_grades', 5)
            ->where('signal_grades.0.signal_type', 'reason_code')
            ->where('signal_grades.0.signals.0.signal_key', 'qb_form_home_edge')
            ->where('signal_grades.0.signals.0.observation_count', 1)
            ->where('signal_grades.0.signals.0.winner_accuracy', 1)
            ->where('signal_grades.0.signals.0.roi', 1.2)
            ->where('signal_grades.0.signals.0.avg_clv', 0.03)
            ->where('signal_grades.0.signals.0.window_count', 1)
            ->where('signal_grades.0.windows.0.season', 2025)
            ->where('signal_grades.1.signal_type', 'risk_flag')
            ->where('signal_grades.2.signal_type', 'matched_rule')
            ->where('signal_grades.3.signal_type', 'pass_rule')
            ->where('signal_grades.4.signal_type', 'validated_combo')
        );
});
