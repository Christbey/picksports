<?php

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

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

it('keeps monitoring win probability evidence distinct from market scalars', function (
    string $market,
    float $baseline,
    float $challenger,
    array $probabilities,
    array $explanation,
    ?float $expectedBaseline,
    ?float $expectedChallenger,
) {
    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'profile_validation',
        modelVersion: 'synthetic-monitor-dto',
        featureVersion: 'nfl-pregame-core-v1',
        blendVersion: 'synthetic-monitor-dto',
        status: 'completed',
        completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $run->id,
        'sport' => 'nfl',
        'market_type' => 'multi_market',
        'model_type' => 'nfl_full_historical_profile',
        'model_version' => 'synthetic-monitor-dto',
        'feature_version' => 'nfl-pregame-core-v1',
        'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => storage_path('framework/testing/synthetic-unmaterialized-monitor-artifact.json'),
        'artifact_hash' => str_repeat('b', 64),
        'status' => 'shadow',
        'evaluation_report_path' => null,
        'evaluation_report_hash' => null,
    ]);
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-11-15',
        'game_time' => '12:00:00',
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 901,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $run->id,
        'model_version' => 'synthetic-monitor-dto',
        'feature_version' => 'nfl-pregame-core-v1',
        'blend_version' => 'synthetic-monitor-dto',
        'features' => [],
        'outputs' => [
            'baseline_predicted_spread' => -3.5,
            'challenger_predicted_spread' => -1.5,
            'baseline_predicted_total' => 44.5,
            'challenger_predicted_total' => 46.5,
            ...$probabilities,
        ],
        'feature_hash' => str_repeat('c', 64),
        'model_metadata' => [],
        'generated_at' => '2026-11-15 16:00:00',
        'game_start_at' => '2026-11-15 18:00:00',
        'features_available_at' => '2026-11-15 16:00:00',
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $output = ShadowModelOutput::query()->create([
        'inference_run_id' => $run->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => 901,
        'market_type' => $market,
        'baseline_output' => $baseline,
        'challenger_output' => $challenger,
        'output_delta' => $challenger - $baseline,
        'status' => 'shadow',
        'explanation' => $explanation,
        'generated_at' => '2026-11-15 16:00:00',
    ]);
    $outputBefore = $output->fresh()->getRawOriginal();
    $snapshotBefore = $snapshot->fresh()->getRawOriginal();
    $artifactBefore = $artifact->fresh()->getRawOriginal();

    // Actual public dashboard method and database query/DTO, not its private mapper.
    // The fixture is read-only synthetic metadata, never a numeric-model approval.
    $dashboard = app(NflModelMonitoringService::class)->dashboard($artifact->id, [], false);
    $rows = collect($dashboard['observations'])->where('id', $output->id)->values();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    $outputAfter = $output->fresh()->getRawOriginal();
    $snapshotAfter = $snapshot->fresh()->getRawOriginal();
    $artifactAfter = $artifact->fresh()->getRawOriginal();
    if ($path = getenv('REVIEW_NFL_MONITOR_DTO_TRACE')) {
        file_put_contents($path, json_encode([
            'market' => $market,
            'source_snapshot_outputs' => $snapshot->outputs,
            'source_scalar' => [$baseline, $challenger],
            'explanation' => $explanation,
            'actual_public_dashboard_observation' => $row,
            'raw_rows_before' => ['output' => $outputBefore, 'snapshot' => $snapshotBefore, 'artifact' => $artifactBefore],
            'raw_rows_after' => ['output' => $outputAfter, 'snapshot' => $snapshotAfter, 'artifact' => $artifactAfter],
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }
    expect($row['market_type'])->toBe($market)
        ->and($row['baseline_output'])->toBe($baseline)
        ->and($row['challenger_output'])->toBe($challenger)
        ->and($row['baseline_outputs']['win_probability'])->toBe($expectedBaseline)
        ->and($row['challenger_outputs']['win_probability'])->toBe($expectedChallenger)
        ->and($output->fresh()->getRawOriginal())->toBe($outputBefore)
        ->and($snapshot->fresh()->getRawOriginal())->toBe($snapshotBefore)
        ->and($artifact->fresh()->getRawOriginal())->toBe($artifactBefore);
    Http::assertNothingSent();
})->with([
    'spread missing win evidence is unavailable' => ['spread', -3.5, -1.5, [], [], null, null],
    'total missing win evidence is unavailable' => ['total', 44.5, 46.5, [], [], null, null],
    'win market keeps its actual scalar fallback' => ['win_probability', 0.6, 0.65, [], [], 0.6, 0.65],
    'nonwin observed zero probabilities stay zero' => ['spread', -3.5, -1.5, ['baseline_win_probability' => 0, 'challenger_win_probability' => 0], [], 0.0, 0.0],
    'nonwin snapshot probabilities stay available' => ['total', 44.5, 46.5, ['baseline_win_probability' => 0.6, 'challenger_win_probability' => 0.65], [], 0.6, 0.65],
    'nonwin explanation challenger remains legitimate evidence' => ['spread', -3.5, -1.5, [], ['challenger_outputs' => ['win_probability' => 0.65]], null, 0.65],
    'snapshot challenger precedes explanation evidence' => ['total', 44.5, 46.5, ['baseline_win_probability' => 0.6, 'challenger_win_probability' => 0.65], ['challenger_outputs' => ['win_probability' => 0.8]], 0.6, 0.65],
]);
