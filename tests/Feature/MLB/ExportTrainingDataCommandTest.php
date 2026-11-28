<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Services\Predictions\SnapshotMetadataStorage;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('mlb', 'commands');

beforeEach(function () {
    Carbon::setTestNow('2026-06-01T00:00:00Z');
    CarbonImmutable::setTestNow('2026-06-01T00:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('exports only trusted canonical mlb snapshots in chronological order', function () {
    $home = Team::factory()->create(['name' => 'Synthetic Export Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Export Away']);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    $earlyGame = createMlbExportGame($home, $away, '2026-07-01 19:10:00');
    $lateGame = createMlbExportGame($home, $away, '2026-07-02 19:10:00');
    $earlyPrediction = createMlbExportPrediction($earlyGame);
    $latePrediction = createMlbExportPrediction($lateGame);

    createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 10:00:00',
        featureValue: 1,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );
    $canonicalObserved = createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 18:30:00',
        featureValue: 2,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );
    createMlbExportSnapshot(
        $earlyGame,
        $earlyPrediction,
        $modelRun->id,
        generatedAt: '2026-07-01 20:00:00',
        featureValue: 99,
        availabilityStatus: 'after_game_start',
        pregameSafe: false,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    $canonicalLateObserved = createMlbExportSnapshot(
        $lateGame,
        $latePrediction,
        $modelRun->id,
        generatedAt: '2026-07-02 18:30:00',
        featureValue: 3,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: [
            'prediction_horizon' => 'Final Pregame',
        ],
        featuresAvailableAt: '2026-07-02 18:30:00',
    );
    createMlbExportSnapshot(
        $lateGame, $latePrediction, $modelRun->id,
        generatedAt: '2026-07-20 12:00:00', featureValue: 97,
        availabilityStatus: 'verified_reconstruction', pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame', 'point_in_time_verified' => true, 'verification_method' => 'source_timestamp_audit'],
        featuresAvailableAt: '2026-07-02 19:10:00',
    );
    createMlbExportSnapshot(
        $lateGame,
        $latePrediction,
        $modelRun->id,
        generatedAt: '2026-07-21 12:00:00',
        featureValue: 98,
        availabilityStatus: 'verified_reconstruction',
        pregameSafe: true,
        lineage: [
            'prediction_horizon' => 'Final Pregame',
            'point_in_time_verified' => false,
        ],
        featuresAvailableAt: '2026-07-02 19:10:00',
    );

    settleMlbExportGames();

    $path = storage_path('app/ml/tests/mlb_trusted_export.csv');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'stale export');

    $exitCode = Artisan::call('mlb:export-training-data', [
        '--season' => [2026],
        '--path' => $path,
    ]);

    expect($exitCode)->toBe(0, Artisan::output());
    $rows = readMlbExportCsv($path);

    expect($exitCode)->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and(array_column($rows, 'game_id'))->toBe([
            (string) $earlyGame->id,
            (string) $lateGame->id,
        ])
        ->and(array_column($rows, 'canonical_snapshot_id'))->toBe([
            (string) $canonicalObserved->id,
            (string) $canonicalLateObserved->id,
        ])
        ->and(array_column($rows, 'feature_signal'))->toBe(['2', '3'])
        ->and(array_column($rows, 'prediction_horizon'))->toBe(['final_pregame', 'final_pregame'])
        ->and($rows[0]['availability_status'])->toBe('observed_pregame')
        ->and($rows[1]['availability_status'])->toBe('observed_pregame')
        ->and($rows[0]['pregame_safe'])->toBe('1')
        ->and($rows[0]['target_home_win'])->toBe('1')
        ->and($rows[0]['target_home_margin'])->toBe('2')
        ->and($rows[0]['target_total_points'])->toBe('8')
        ->and($rows[0]['config_hash'])->toHaveLength(64)
        ->and($rows[0]['feature_schema_hash'])->toHaveLength(64)
        ->and($rows[0]['target_hash'])->toHaveLength(64)
        ->and($rows[0]['row_lineage_hash'])->toHaveLength(64)
        ->and($rows[0]['dataset_schema_hash'])->toBe('c91d64f5477e3b287f50ee66dcb4b18364ca5cceb03b600d59c299870e68fe91')
        ->and(hash('sha256', file($path)[0]))->toBe('8bccdafc77a263e18a897db7a8f6383681800568b27827797582c05a14687d30')
        ->and(mlbExportTemporaryFiles($path))->toBe([]);
    $proof = json_decode(file_get_contents($path.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($proof['ignored_legacy_snapshots'])->toBe(3)
        ->and($proof['selection_scope'])->toBe('latest_claimed_capture_per_game_no_invalid_fallback')
        ->and($proof['research_only'])->toBeFalse();
});

it('exports only the latest trusted snapshot when a game has multiple horizons', function () {
    $home = Team::factory()->create(['name' => 'Synthetic Export Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Export Away']);
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-04 10:00:00',
        featureValue: 1,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Morning'],
    );
    $latest = createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-04 18:30:00',
        featureValue: 2,
        availabilityStatus: 'observed_pregame',
        pregameSafe: true,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    settleMlbExportGames();

    $path = storage_path('app/ml/tests/mlb_single_game_export.csv');
    @unlink($path);

    $exitCode = Artisan::call('mlb:export-training-data', ['--path' => $path]);
    expect($exitCode)->toBe(0, Artisan::output());

    $rows = readMlbExportCsv($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['canonical_snapshot_id'])->toBe((string) $latest->id)
        ->and($rows[0]['prediction_horizon'])->toBe('final_pregame')
        ->and($rows[0]['feature_signal'])->toBe('2');
});

it('requires an explicit research override to export unsafe mlb snapshots', function () {
    $home = Team::factory()->create(['name' => 'Synthetic Export Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Export Away']);
    $game = createMlbExportGame($home, $away, '2026-07-03 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $modelRun = app(ModelRunRecorder::class)->forPrediction(
        sport: 'mlb',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
    );

    createMlbExportSnapshot(
        $game,
        $prediction,
        $modelRun->id,
        generatedAt: '2026-07-03 20:00:00',
        featureValue: 8,
        availabilityStatus: 'after_game_start',
        pregameSafe: false,
        lineage: ['prediction_horizon' => 'Final Pregame'],
    );

    settleMlbExportGames();

    $path = storage_path('app/ml/tests/mlb_unsafe_export.csv');
    @unlink($path);

    $exitCode = Artisan::call('mlb:export-training-data', ['--path' => $path]);

    expect($exitCode)->toBe(1)
        ->and(file_exists($path))->toBeFalse()
        ->and(Artisan::output())->toContain('No MLB rows met');

    Artisan::call('mlb:export-training-data', [
        '--path' => $path,
        '--include-unsafe' => true,
    ]);

    $rows = readMlbExportCsv($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['pregame_safe'])->toBe('0')
        ->and($rows[0]['availability_status'])->toBe('after_game_start')
        ->and(Artisan::output())->toContain('no (research override)');
});

it('fails an empty mlb export and removes a stale destination', function () {
    settleMlbExportGames();

    $path = storage_path('app/ml/tests/mlb_empty_export.csv');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'stale export');

    $exitCode = Artisan::call('mlb:export-training-data', [
        '--season' => [1900],
        '--path' => $path,
    ]);

    expect($exitCode)->toBe(1)
        ->and(file_exists($path))->toBeFalse()
        ->and(mlbExportTemporaryFiles($path))->toBe([])
        ->and(Artisan::output())->toContain('No MLB rows met');
});

it('refuses an older owned capture when a newer claimed capture is unqualified', function () {
    $home = Team::factory()->create(['name' => 'Synthetic Export Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Export Away']);
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $run = app(ModelRunRecorder::class)->forPrediction('mlb', 'rules-v1', 'core-v3', 'baseline-v1');
    foreach (['2026-07-04 18:30:00', '2026-07-04 20:00:00'] as $generatedAt) {
        createMlbExportSnapshot($game, $prediction, $run->id, $generatedAt, 1, 'observed_pregame', true, ['prediction_horizon' => 'Final Pregame']);
    }
    settleMlbExportGames();
    $path = storage_path('app/ml/tests/mlb_owned_unqualified_export.csv');
    @unlink($path);
    $exitCode = Artisan::call('mlb:export-training-data', ['--path' => $path]);
    expect($exitCode)->toBe(1)->and(file_exists($path))->toBeFalse();
});

it('does not silently fall back from a malformed claimed capture', function () {
    $home = Team::factory()->create(['name' => 'Synthetic Export Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Export Away']);
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $run = app(ModelRunRecorder::class)->forPrediction('mlb', 'rules-v1', 'core-v3', 'baseline-v1');
    createMlbExportSnapshot($game, $prediction, $run->id, '2026-07-04 18:30:00', 1, 'observed_pregame', true, []);
    $bad = createMlbExportSnapshot($game, $prediction, $run->id, '2026-07-04 20:00:00', 2, 'after_game_start', false, [], legacyMarkerClaim: true);
    expect(array_key_exists('mlb_capture_integrity', $bad->model_metadata))->toBeTrue();
    settleMlbExportGames();
    $path = storage_path('app/ml/tests/mlb_malformed_claim_export.csv');
    @unlink($path);
    $exitCode = Artisan::call('mlb:export-training-data', ['--path' => $path]);
    expect($exitCode)->toBe(1)->and(file_exists($path))->toBeFalse();
});

function createMlbExportGame(Team $home, Team $away, string $startAt): Game
{
    $start = Carbon::parse($startAt);

    return Game::factory()->withSyntheticWallStart((string) config('app.timezone'))->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $start->toDateString(),
        'game_time' => $start->format('H:i:s'),
        'home_score' => null,
        'away_score' => null,
    ]);
}

function settleMlbExportGames(): void
{
    foreach (Game::query()->get() as $game) {
        $finishedAt = MlbGameStart::for($game)->addHours(6);
        $clock = Carbon::now('UTC')->max($finishedAt);
        Carbon::setTestNow($clock);
        CarbonImmutable::setTestNow($clock);
        $game->forceFill(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 3])->save();
        $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
        if (PredictionEvaluation::query()->where('prediction_table', 'mlb_predictions')->where('prediction_id', $prediction->id)->exists()) {
            continue;
        }
        PredictionEvaluation::query()->create([
            'sport' => 'mlb',
            'prediction_table' => 'mlb_predictions',
            'prediction_id' => $prediction->id,
            'game_id' => $game->id,
            'model_version' => 'rules-v1',
            'feature_version' => 'core-v3',
            'blend_version' => 'baseline-v1',
            'actuals' => [
                'actual_spread' => 2.0,
                'actual_total' => 8.0,
            ],
            'errors' => [],
            'market_comparison' => [],
            'evaluated_at' => now(),
        ]);
    }
}

function createMlbExportPrediction(Game $game): Prediction
{
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 57,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
    ]);

    return $prediction;
}

/**
 * @param  array<string, mixed>  $lineage
 */
function createMlbExportSnapshot(
    Game $game,
    Prediction $prediction,
    string $modelRunId,
    string $generatedAt,
    int $featureValue,
    string $availabilityStatus,
    bool $pregameSafe,
    array $lineage,
    ?string $featuresAvailableAt = null,
    bool $legacyMarkerClaim = false,
    array $modelMetadata = [],
): PredictionFeatureSnapshot {
    $gameStart = Carbon::parse($game->game_date->toDateString().' '.$game->game_time);

    $capture = Carbon::parse($generatedAt, (string) config('app.timezone'));
    Carbon::setTestNow($capture);
    CarbonImmutable::setTestNow($capture);
    if ($availabilityStatus === 'observed_pregame' && $pregameSafe) {
        $receipt = app(GameOddsSnapshotRecorder::class)->record('mlb', $game,
            ['id' => 'synthetic-export-'.$game->id, 'commence_time' => MlbGameStart::for($game)->toIso8601String()],
            ['home_team' => $game->homeTeam->name, 'away_team' => $game->awayTeam->name, 'bookmakers' => [['key' => 'synthetic-export', 'markets' => [
                ['key' => 'spreads', 'outcomes' => [
                    ['name' => $game->homeTeam->name, 'point' => -1.5, 'price' => -110],
                    ['name' => $game->awayTeam->name, 'point' => 1.5, 'price' => -110],
                ]],
            ]]]], $capture, 'synthetic-test');

        return app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
            'model_version' => 'rules-v1', 'feature_version' => 'core-v3', 'blend_version' => 'baseline-v1',
            'features' => ['signal' => $featureValue],
            'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .57],
            'market_context' => ['vegas_spread' => -1.5, 'safety' => ['snapshot_id' => $receipt?->id]],
            'generated_at' => $capture,
            'features_available_at' => Carbon::parse($featuresAvailableAt ?? $generatedAt),
            'pregame_safe' => true, 'availability_status' => 'observed_pregame',
            'source_timestamps' => ['features' => $featuresAvailableAt ?? $generatedAt],
            'lineage_metadata' => $lineage,
            'model_metadata' => [...$modelMetadata, 'prediction_horizon' => $lineage['prediction_horizon'] ?? null],
        ]);
    }

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => ['signal' => $featureValue],
        'outputs' => [
            'predicted_spread' => 1.5,
            'predicted_total' => 8.5,
            'win_probability' => 0.57,
        ],
        'market_context' => ['vegas_spread' => -1.5],
        'feature_hash' => hash('sha256', 'feature-'.$game->id.'-'.$featureValue),
        'generated_at' => Carbon::parse($generatedAt),
        'game_start_at' => $gameStart,
        'features_available_at' => Carbon::parse($featuresAvailableAt ?? $generatedAt),
        'pregame_safe' => $pregameSafe,
        'availability_status' => $availabilityStatus,
        'source_timestamps' => ['features' => $featuresAvailableAt ?? $generatedAt],
        'lineage_metadata' => $lineage,
        'model_metadata' => $legacyMarkerClaim ? ['mlb_capture_integrity' => null] : null,
    ]);
}

/**
 * @return list<array<string, string>>
 */
function readMlbExportCsv(string $path): array
{
    $csv = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
    $headers = array_shift($csv);

    return array_map(
        fn (array $row): array => array_combine($headers, $row),
        $csv,
    );
}

/**
 * @return list<string>
 */
function mlbExportTemporaryFiles(string $path): array
{
    return glob(dirname($path).'/.'.basename($path).'.*.tmp') ?: [];
}

it('exports byte-identical training rows and metadata after snapshot metadata sharing and restore', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $run = app(ModelRunRecorder::class)->forPrediction('mlb', 'rules-v1', 'core-v3', 'baseline-v1');
    $snapshot = createMlbExportSnapshot($game, $prediction, $run->id, '2026-07-04 18:30:00', 2,
        'observed_pregame', true, ['prediction_horizon' => 'Final Pregame'], modelMetadata: ['diagnostics' => str_repeat('source', 1000)]);
    settleMlbExportGames();
    $path = storage_path('app/ml/tests/mlb_shared_metadata_export.csv');
    $options = ['--path' => $path, '--include-metadata' => true];
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(0);
    $before = file_get_contents($path);
    $compact = ['--through-id' => $snapshot->id, '--apply' => true];

    expect(Artisan::call('sports:compact-snapshot-metadata', $compact))->toBe(0)
        ->and(Artisan::call('mlb:export-training-data', $options))->toBe(0)
        ->and(file_get_contents($path))->toBe($before)
        ->and(Artisan::call('sports:compact-snapshot-metadata', [...$compact, '--restore' => true]))->toBe(0)
        ->and(Artisan::call('mlb:export-training-data', $options))->toBe(0)
        ->and(file_get_contents($path))->toBe($before);
});

it('selects the same latest snapshot across batched out-of-order IDs without metadata query growth per row', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = createMlbExportGame($home, $away, '2026-07-04 19:10:00');
    $prediction = createMlbExportPrediction($game);
    $run = app(ModelRunRecorder::class)->forPrediction('mlb', 'rules-v1', 'core-v3', 'baseline-v1');
    $reference = app(SnapshotMetadataStorage::class)->store(['prediction_horizon' => 'Final Pregame']);
    $latest = null;
    foreach (range(1, 505) as $index) {
        $snapshot = createMlbExportSnapshot($game, $prediction, $run->id,
            $index === 1 ? '2026-07-04 18:30:00' : '2026-07-04 10:00:00', $index,
            'reconstructed_unsafe', false, []);
        $snapshot->update(['model_metadata' => $reference]);
        $latest ??= $snapshot;
    }
    $path = storage_path('app/ml/tests/mlb_batched_metadata_export.csv');
    settleMlbExportGames();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(Artisan::call('mlb:export-training-data', ['--path' => $path, '--include-unsafe' => true]))->toBe(0);
    expect(json_decode(file_get_contents($path.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR)['research_only'])->toBeTrue();
    $payloadQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'snapshot_metadata_payloads'));
    DB::disableQueryLog();
    $rows = readMlbExportCsv($path);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['canonical_snapshot_id'])->toBe((string) $latest->id)
        ->and($rows[0]['feature_signal'])->toBe('1')
        // Header/schema discovery and CSV emission each hydrate one selected batch.
        ->and($payloadQueries)->toHaveCount(2);
});

it('keeps chronological output across multiple winner batches while loading only selected metadata', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $run = app(ModelRunRecorder::class)->forPrediction('mlb', 'rules-v1', 'core-v3', 'baseline-v1');
    $reference = app(SnapshotMetadataStorage::class)->store(['prediction_horizon' => 'Final Pregame']);
    $gameIds = [];
    foreach (range(0, 100) as $index) {
        $start = Carbon::parse('2026-04-01 19:10:00')->addDays($index);
        $game = createMlbExportGame($home, $away, $start->toDateTimeString());
        $prediction = createMlbExportPrediction($game);
        $snapshot = createMlbExportSnapshot($game, $prediction, $run->id, $start->subHour()->toDateTimeString(),
            $index, 'reconstructed_unsafe', false, []);
        $snapshot->update(['model_metadata' => $reference]);
        $gameIds[] = (string) $game->id;
    }
    $path = storage_path('app/ml/tests/mlb_winner_batches.csv');
    settleMlbExportGames();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(Artisan::call('mlb:export-training-data', ['--path' => $path, '--include-unsafe' => true]))->toBe(0);
    expect(json_decode(file_get_contents($path.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR)['research_only'])->toBeTrue();
    $payloadQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'snapshot_metadata_payloads'));
    DB::disableQueryLog();
    expect(array_column(readMlbExportCsv($path), 'game_id'))->toBe($gameIds)
        // Two selected batches in each of the two bounded export passes.
        ->and($payloadQueries)->toHaveCount(4);
});
