<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\CsvDataset;
use App\Services\MLB\MlbSnapshotCaptureIntegrity;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularDatasetExporter;
use App\Services\MLB\MlbTabularSnapshotReader;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Support\MLB\MlbGameStart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function mlbLegacyTimingExportFixture(string $availability, bool $knownGame = false): PredictionFeatureSnapshot
{
    $factory = Game::factory();
    if ($knownGame) {
        $factory = $factory->withSyntheticWallStart('America/Chicago');
    }
    $game = $factory->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-07-02', 'game_time' => '19:10:00', 'status' => 'STATUS_FINAL']);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5,
        'win_probability' => 0.57, 'confidence_score' => 57, 'model_version' => 'rules-v1', 'feature_version' => 'core-v3', 'blend_version' => 'baseline-v1']);
    PredictionEvaluation::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $prediction->id,
        'game_id' => $game->id, 'model_version' => 'rules-v1', 'feature_version' => 'core-v3', 'blend_version' => 'baseline-v1',
        'actuals' => ['actual_spread' => 2, 'actual_total' => 8], 'errors' => [], 'market_comparison' => [], 'evaluated_at' => now()]);

    // Synthetic retained legacy row: these assertions are precisely the old unsupported trust contract.
    return PredictionFeatureSnapshot::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $prediction->id,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'rules-v1', 'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1', 'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57],
        'market_context' => [], 'feature_hash' => hash('sha256', 'legacy-synthetic'), 'generated_at' => Carbon::parse('2026-07-01 10:00:00'),
        'game_start_at' => Carbon::parse('2026-07-02 19:10:00'), 'features_available_at' => Carbon::parse('2026-07-01 10:00:00'),
        'pregame_safe' => true, 'availability_status' => $availability,
        'lineage_metadata' => ['point_in_time_verified' => true, 'verification_method' => 'legacy-assertion']])->fresh();
}

it('declines legacy timing assertions as trusted MLB tabular training evidence without rewriting them', function (string $availability, bool $knownGame) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Http::preventStrayRequests();
    $snapshot = mlbLegacyTimingExportFixture($availability, $knownGame);
    $before = $snapshot->getAttributes();
    $path = storage_path('framework/testing/mlb-timing-export-'.Str::uuid().'.csv');
    try {
        expect(Artisan::call('mlb:export-training-data', ['--path' => $path, '--season' => [2026]]))->toBe(1)
            ->and(file_exists($path))->toBeFalse()
            ->and($snapshot->fresh()->getAttributes())->toBe($before);
        Http::assertNothingSent();
    } finally {
        @unlink($path);
    }
})->with([
    ['observed_pregame', false],
    ['verified_reconstruction', false],
    ['observed_pregame', true],
]);

it('retains an explicit research-only export of legacy MLB evidence without promoting its trust', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Http::preventStrayRequests();
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $before = $snapshot->getAttributes();
    $path = storage_path('framework/testing/mlb-timing-research-'.Str::uuid().'.csv');
    try {
        expect(Artisan::call('mlb:export-training-data', ['--path' => $path, '--include-unsafe' => true]))->toBe(0)
            ->and(file_exists($path))->toBeTrue()
            ->and(Artisan::output())->toContain('no (research override)')
            ->and($snapshot->fresh()->getAttributes())->toBe($before);
        Http::assertNothingSent();
    } finally {
        @unlink($path);
    }
});

it('roundtrips the authoritative MLB kickoff through the actual generic snapshot recorder', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Http::preventStrayRequests();
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'game_date' => '2026-07-02', 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5,
        'win_probability' => 0.57, 'confidence_score' => 57]);
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'mlb', [], [
        'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57],
        'market_context' => [],
    ]);
    expect($snapshot->fresh()->game_start_at?->getTimestamp())->toBe(MlbGameStart::for($game)?->getTimestamp());
    Http::assertNothingSent();
});

/** @return array{PredictionFeatureSnapshot,Game,Prediction} */
function mlbTypedTimingCapture(): array
{
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-07-02', 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57, 'confidence_score' => 57]);
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57], 'market_context' => [],
        'model_metadata' => ['mlb_capture_integrity' => ['schema' => 'fake-caller-schema', 'qualified' => true]],
    ]);

    return [$snapshot->fresh(), $game->fresh(), $prediction];
}

it('owns a current MLB capture marker and keeps it outside numeric features', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    $inspection = app(MlbSnapshotCaptureIntegrity::class)->inspect($snapshot, $game);
    expect($inspection['qualified'])->toBeTrue()
        ->and(data_get($snapshot->model_metadata, 'mlb_capture_integrity.schema'))->toBe(MlbSnapshotCaptureIntegrity::SCHEMA)
        ->and($snapshot->features)->toBe(['signal' => 1]);
});

it('protects future MLB captures through ordinary quiet and numeric model APIs', function (string $method) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot] = mlbTypedTimingCapture();
    $before = $snapshot->getAttributes();
    expect(fn () => match ($method) {
        'save' => $snapshot->fill(['features' => ['signal' => 999]])->save(),
        'saveQuietly' => $snapshot->fill(['features' => ['signal' => 999]])->saveQuietly(),
        'updateQuietly' => $snapshot->updateQuietly(['outputs' => ['win_probability' => 0.99]]),
        'delete' => $snapshot->delete(),
        'deleteQuietly' => $snapshot->deleteQuietly(),
        'increment' => $snapshot->increment('prediction_id'),
        'decrementQuietly' => $snapshot->decrementQuietly('game_id'),
    })->toThrow(LogicException::class);
    expect($snapshot->fresh()->getAttributes())->toBe($before);
})->with(['save', 'saveQuietly', 'updateQuietly', 'delete', 'deleteQuietly', 'increment', 'decrementQuietly']);

it('never adds MLB capture certification to an existing legacy snapshot by model mutation', function (bool $quiet) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $before = $snapshot->getAttributes();
    $attributes = ['model_metadata' => ['mlb_capture_integrity' => ['schema' => MlbSnapshotCaptureIntegrity::SCHEMA]]];
    expect(fn () => $quiet ? $snapshot->updateQuietly($attributes) : $snapshot->update($attributes))->toThrow(LogicException::class);
    expect($snapshot->fresh()->getAttributes())->toBe($before);
})->with([false, true]);

it('retains legacy nonshadow projection mutability without treating it as timing proof', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $snapshot->updateQuietly(['features' => ['signal' => 2]]);
    $game = Game::findOrFail($snapshot->game_id);
    expect($snapshot->fresh()->features)->toBe(['signal' => 2])
        ->and(app(MlbSnapshotCaptureIntegrity::class)->inspect($snapshot->fresh(), $game)['qualified'])->toBeFalse();
});

it('declines a raw corrupted MLB capture and changed current Game context without repairing either', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    $integrity = app(MlbSnapshotCaptureIntegrity::class);
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->update(['features' => json_encode(['signal' => 999], JSON_THROW_ON_ERROR)]);
    $before = $snapshot->fresh()->getAttributes();
    expect($integrity->inspect($snapshot->fresh(), $game)['qualified'])->toBeFalse()
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
    $game->update(['game_time' => '20:10:00']);
    expect($integrity->inspect($snapshot->fresh(), $game->fresh())['qualified'])->toBeFalse();
});

it('reads MLB tabular bodies only after bounded scalar byte admission', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $reader = app(MlbTabularSnapshotReader::class);
    $query = $reader->query([2026]);
    expect($reader->assertScope($query)['candidates'])->toBe(1);
    $batch = iterator_to_array($reader->batches($query))[0];
    $bytes = $tokens = 0;
    $rows = $reader->hydrate($query, $batch, $bytes, $tokens);
    expect($rows)->toHaveCount(1)->and($rows->first()->features)->toBe($snapshot->features)
        ->and($bytes)->toBeGreaterThan(0)->and($tokens)->toBeGreaterThan(0);
});

it('refuses oversized MLB tabular source JSON before body queries', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    // Trusted SQL corruption fixture: model-method protections are intentionally bypassed here.
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update([
        'features' => json_encode(['data' => str_repeat('a', 262144)]),
    ]);
    $reader = app(MlbTabularSnapshotReader::class);
    $connection = $reader->connection();
    $connection->enableQueryLog();
    try {
        expect(fn () => $reader->assertScope($reader->query()))->toThrow(RuntimeException::class, 'before body reads');
        expect(collect($connection->getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'payload_admitted')))->toBeFalse();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
});

it('rechecks MLB body growth in the fetching SQL rather than trusting scalar preflight', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $reader = app(MlbTabularSnapshotReader::class);
    $query = $reader->query();
    $reader->assertScope($query);
    $batch = iterator_to_array($reader->batches($query))[0];
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update([
        'features' => json_encode(['data' => str_repeat('a', 262144)]),
    ]);
    $bytes = $tokens = 0;
    expect(fn () => $reader->hydrate($query, $batch, $bytes, $tokens))->toThrow(RuntimeException::class, 'no JSON decoded')
        ->and($bytes)->toBe(0)->and($tokens)->toBe(0);
});

it('refuses dense MLB JSON before any model body is hydrated', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    $snapshot = mlbLegacyTimingExportFixture('observed_pregame');
    $reader = app(MlbTabularSnapshotReader::class);
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update([
        'features' => '['.implode(',', array_fill(0, 20000, 0)).']',
    ]);
    $query = $reader->query();
    $reader->assertScope($query);
    $batch = iterator_to_array($reader->batches($query))[0];
    $bytes = $tokens = 0;
    expect(fn () => $reader->hydrate($query, $batch, $bytes, $tokens))->toThrow(RuntimeException::class, 'token budget')
        ->and($bytes)->toBe(0)->and($tokens)->toBe(0);
});

function mlbTypedTimingEvaluation(PredictionFeatureSnapshot $snapshot, Game $game): void
{
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 3]);
    PredictionEvaluation::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $snapshot->prediction_id,
        'game_id' => $game->id, 'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version, 'blend_version' => $snapshot->blend_version,
        'actuals' => ['actual_spread' => 2, 'actual_total' => 8], 'errors' => [], 'market_comparison' => [], 'evaluated_at' => now()]);
}

it('streams a real current MLB capture into a matching bounded dataset proof', function () {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    mlbTypedTimingEvaluation($snapshot, $game);
    $path = storage_path('framework/testing/mlb-typed-export-'.Str::uuid().'.csv');
    try {
        expect(Artisan::call('mlb:export-training-data', ['--path' => $path, '--season' => [2026]]))->toBe(0, Artisan::output());
        $proof = json_decode(file_get_contents($path.'.manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        expect($proof['schema'])->toBe(MlbTabularDatasetExporter::POLICY)
            ->and($proof['research_only'])->toBeFalse()->and($proof['limit_hit'])->toBeFalse()
            ->and($proof['rows'])->toBe(1)->and($proof['dataset_sha256'])->toBe(hash_file('sha256', $path))
            ->and($proof['dataset_bytes'])->toBe(filesize($path))
            ->and($proof['references'][0]['qualified'])->toBeTrue()
            ->and($proof['references'][0]['snapshot_id'])->toBe($snapshot->id);
        $rows = app(CsvDataset::class)->read($path);
        expect($rows)->toHaveCount(1)->and($rows[0]['target_home_margin'])->toBe('2')
            ->and($rows[0]['feature_signal'])->toBe('1');
    } finally {
        @unlink($path);
        @unlink($path.'.manifest.json');
    }
});

it('declines a corrupted formerly marked MLB capture at the actual exporter boundary', function (string $corruption) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    mlbTypedTimingEvaluation($snapshot, $game);
    // Trusted corruption fixtures; no application API may perform these writes.
    if ($corruption === 'payload') {
        DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['features' => json_encode(['signal' => 999])]);
    } else {
        DB::table('mlb_games')->where('id', $game->id)->update(['game_time' => '20:10:00']);
    }
    $path = storage_path('framework/testing/mlb-corrupt-export-'.Str::uuid().'.csv');
    try {
        expect(Artisan::call('mlb:export-training-data', ['--path' => $path]))->toBe(1)
            ->and(file_exists($path))->toBeFalse()->and(file_exists($path.'.manifest.json'))->toBeFalse();
    } finally {
        @unlink($path);
        @unlink($path.'.manifest.json');
    }
})->with(['payload', 'current_schedule']);

it('reconstructs actual MLB CSV values from protected evidence rather than trusting its manifest', function (string $change) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    mlbTypedTimingEvaluation($snapshot, $game);
    $path = storage_path('framework/testing/mlb-admission-export-'.Str::uuid().'.csv');
    $admission = app(MlbTabularDatasetAdmission::class);
    try {
        expect(Artisan::call('mlb:export-training-data', ['--path' => $path]))->toBe(0, Artisan::output());
        if ($change === 'none') {
            expect($admission->inspect($path)['rows'])->toBe(1);

            return;
        }
        $manifest = $path.'.manifest.json';
        $proof = json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        if ($change === 'csv_with_updated_claimed_hash') {
            $raw = file_get_contents($path);
            $rows = app(CsvDataset::class)->read($path);
            $rows[0]['target_home_margin'] = '999';
            app(CsvDataset::class)->write($path, $rows);
            $proof['dataset_sha256'] = hash_file('sha256', $path);
            $proof['dataset_bytes'] = filesize($path);
        } elseif ($change === 'research') {
            $proof['research_only'] = true;
        } elseif ($change === 'truncated_scope') {
            $proof['limit_hit'] = true;
        } elseif ($change === 'current_game_drift') {
            DB::table('mlb_games')->where('id', $game->id)->update(['game_time' => '20:30:00']);
        } elseif ($change === 'current_target_drift') {
            DB::table('prediction_evaluations')->where('id', $proof['references'][0]['evaluation_id'])->update(['actuals' => json_encode(['actual_spread' => 99, 'actual_total' => 101])]);
        } elseif ($change === 'extra_header_with_updated_claimed_hash') {
            $rows = app(CsvDataset::class)->read($path);
            $rows[0]['feature_invented_signal'] = '42';
            app(CsvDataset::class)->write($path, $rows);
            $proof['headers'][] = 'feature_invented_signal';
            $proof['dataset_sha256'] = hash_file('sha256', $path);
            $proof['dataset_bytes'] = filesize($path);
        } elseif ($change === 'dense_manifest') {
            $proof['ignored_expansion'] = array_fill(0, 60000, 0);
        }
        file_put_contents($manifest, json_encode($proof, JSON_THROW_ON_ERROR));
        expect(fn () => $admission->inspect($path))->toThrow(RuntimeException::class);
    } finally {
        @unlink($path);
        @unlink($path.'.manifest.json');
    }
})->with(['none', 'csv_with_updated_claimed_hash', 'research', 'truncated_scope', 'current_game_drift', 'current_target_drift', 'extra_header_with_updated_claimed_hash', 'dense_manifest']);

it('requires current final scored targets for trusted MLB datasets while preserving observed zero', function (string $condition) {
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    [$snapshot, $game] = mlbTypedTimingCapture();
    mlbTypedTimingEvaluation($snapshot, $game);
    if ($condition === 'score_correction') {
        $game->update(['home_score' => 6]);
    } elseif ($condition === 'not_final') {
        $game->update(['status' => 'STATUS_SCHEDULED']);
    } elseif ($condition === 'missing_score') {
        $game->update(['home_score' => null]);
    } elseif ($condition === 'malformed_score') {
        DB::table('mlb_games')->where('id', $game->id)->update(['home_score' => 'not-observed']);
    } else {
        $home = 0;
        $away = $condition === 'tie' ? 0 : 3;
        $game->update(['home_score' => $home, 'away_score' => $away]);
        DB::table('prediction_evaluations')->where('prediction_id', $snapshot->prediction_id)->update([
            'actuals' => json_encode(['actual_spread' => $home - $away, 'actual_total' => $home + $away]),
        ]);
    }
    $path = storage_path('framework/testing/mlb-target-export-'.Str::uuid().'.csv');
    try {
        $exit = Artisan::call('mlb:export-training-data', ['--path' => $path]);
        if ($condition === 'observed_zero') {
            expect($exit)->toBe(0, Artisan::output());
            $row = app(CsvDataset::class)->read($path)[0];
            expect($row['target_home_margin'])->toBe('-3')->and($row['target_total_points'])->toBe('3')->and($row['target_home_win'])->toBe('0');
        } else {
            expect($exit)->toBe(1)->and(file_exists($path))->toBeFalse();
        }
    } finally {
        @unlink($path);
        @unlink($path.'.manifest.json');
    }
})->with(['score_correction', 'not_final', 'missing_score', 'malformed_score', 'tie', 'observed_zero']);
