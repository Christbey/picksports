<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\NFL\NflModelMonitoringService;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('admits only compatible settled home-win probability observations without changing stored evidence', function (
    string $outputMarket, string $decisionMarket, string $marketKey,
    float $baseline, float $challenger, int $result, string $side,
    int $expectedCount, ?float $expectedBaseline, ?float $expectedChallenger,
) {
    Http::preventStrayRequests();
    Queue::fake();
    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl', runType: 'profile_validation', modelVersion: 'synthetic-cohort-guard',
        featureVersion: 'nfl-pregame-core-v1', blendVersion: 'synthetic-cohort-guard', status: 'completed', completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(), 'training_run_id' => $run->id, 'sport' => 'nfl',
        'market_type' => 'multi_market', 'model_type' => 'nfl_full_historical_profile',
        'model_version' => 'synthetic-cohort-guard', 'feature_version' => 'nfl-pregame-core-v1',
        'dataset_hash' => str_repeat('a', 64), 'artifact_hash' => str_repeat('b', 64),
        'artifact_path' => storage_path('framework/testing/unmaterialized-cohort-guard.json'),
        'evaluation_report_path' => null, 'evaluation_report_hash' => null, 'status' => 'shadow',
    ]);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-17', 'game_time' => '20:00:00', 'home_score' => $result < 0 ? 20 : ($result === 0 ? 20 : 27), 'away_score' => $result < 0 ? 27 : 20,
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 901, 'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $run->id,
        'model_version' => 'synthetic-cohort-guard', 'feature_version' => 'nfl-pregame-core-v1', 'blend_version' => 'synthetic-cohort-guard',
        'features' => [], 'outputs' => [], 'feature_hash' => str_repeat('c', 64), 'model_metadata' => [],
        'generated_at' => '2026-09-17 12:00:00', 'features_available_at' => '2026-09-17 12:00:00',
        'game_start_at' => '2026-09-17 20:00:00', 'pregame_safe' => true, 'availability_status' => 'observed_pregame',
    ]);
    $output = ShadowModelOutput::query()->create([
        'inference_run_id' => $run->id, 'model_artifact_id' => $artifact->id, 'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions', 'prediction_id' => 901, 'market_type' => $outputMarket,
        'baseline_output' => $baseline, 'challenger_output' => $challenger, 'output_delta' => $challenger - $baseline,
        'status' => 'shadow', 'explanation' => [], 'generated_at' => '2026-09-17 12:00:00',
    ]);
    // Reader-admission fixtures deliberately include stored metadata that is not a valid moneyline probability cohort.
    // They do not claim the active producer generated the invalid combinations or approve a numeric model.
    $decision = BetDecision::query()->create([
        'decision_hash' => hash('sha256', (string) Str::uuid()), 'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $run->id, 'model_artifact_id' => $artifact->id, 'shadow_model_output_id' => $output->id,
        'prediction_feature_snapshot_id' => $snapshot->id, 'source_table' => 'shadow_model_outputs', 'source_id' => $output->id,
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id, 'prediction_table' => 'nfl_predictions', 'prediction_id' => 901,
        'market_type' => $decisionMarket, 'market_key' => $marketKey, 'side' => $side,
        'status' => 'shadow_no_bet', 'is_public' => false, 'is_tracking_only' => true, 'is_bet' => false,
        'pregame_safe' => true, 'eligibility_reasons' => ['artifact_not_promoted_at_decision_time'],
        'decided_at' => '2026-09-17 12:00:00', 'locked_at' => '2026-09-17 12:00:00', 'game_start_at' => '2026-09-17 20:00:00',
    ]);
    $settlement = BetSettlement::query()->create([
        'bet_decision_id' => $decision->id, 'result_status' => $result === 0 ? 'push' : 'win',
        'result_value' => $result, 'profit_units' => 0, 'graded_at' => now(), 'settled_at' => now(),
        'metadata' => ['home_margin' => $decisionMarket === 'total' ? 7 : $result, 'total_score' => $result === 0 ? 40 : 47, 'shadow_profit_units' => 0],
    ]);
    $models = [$output, $snapshot, $decision, $settlement];
    $before = array_map(fn ($model) => $model->fresh()->getRawOriginal(), $models);
    $summary = app(NflModelMonitoringService::class)->dashboard($artifact->id, [], false)['summary'];
    $after = array_map(fn ($model) => $model->fresh()->getRawOriginal(), $models);
    if ($path = getenv('REVIEW_NFL_CALIBRATION_GUARD_TRACE')) {
        file_put_contents($path, json_encode(['output_market' => $outputMarket, 'decision_market' => $decisionMarket, 'probabilities' => [$baseline, $challenger], 'home_result' => $result, 'summary' => $summary, 'raw_before' => $before, 'raw_after' => $after], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }
    expect($summary['calibration_games'])->toBe($expectedCount)
        ->and($summary['baseline_brier'])->toBe($expectedBaseline)
        ->and($summary['challenger_brier'])->toBe($expectedChallenger)
        ->and($after)->toBe($before);
    Http::assertNothingSent();
})->with([
    'probability endpoints with home winner stay valid observations' => ['win_probability', 'moneyline', 'h2h', 0.0, 1.0, 7, 'home', 1, 1.0, 0.0],
    'away selection keeps home-winner target semantics' => ['win_probability', 'moneyline', 'h2h', 1.0, 0.0, -7, 'away', 1, 1.0, 0.0],
    'negative baseline probability is not calibration evidence' => ['win_probability', 'moneyline', 'h2h', -0.1, 0.65, 7, 'home', 0, null, null],
    'challenger probability above one is not calibration evidence' => ['win_probability', 'moneyline', 'h2h', 0.6, 1.1, 7, 'home', 0, null, null],
    'point market cannot masquerade as probability even inside unit range' => ['spread', 'moneyline', 'h2h', 0.6, 0.65, 7, 'home', 0, null, null],
    'total decision is not home-winner probability calibration' => ['win_probability', 'total', 'totals', 0.6, 0.65, 47, 'over', 0, null, null],
    'tied final remains excluded rather than a binary home result' => ['win_probability', 'moneyline', 'h2h', 0.6, 0.65, 0, 'home', 0, null, null],
]);
