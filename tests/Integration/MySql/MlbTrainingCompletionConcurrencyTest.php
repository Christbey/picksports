<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Services\ML\TrainingAttemptService;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbArtifactCohortState;
use App\Services\MLB\MlbTabularSourceFence;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\EloClockFixture;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Actual MLB completion contention requires disposable MySQL.');
    }
    $this->nativeDefault = DB::getDefaultConnection();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    EloClockFixture::prepare();
    config(['database.connections.completion_peer' => config('database.connections.'.$this->nativeDefault)]);
    DB::setDefaultConnection('completion_peer');
    EloClockFixture::prepare();
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::setDefaultConnection($this->nativeDefault);
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('completion_peer')->getPdo());
    Storage::fake('mlb-native-completion');
    Storage::fake('mlb-native-completion-cache');
    $this->nativeRoot = storage_path('framework/testing/mlb-native-completion-'.Str::uuid());
    config(['ml.storage.disk' => 'mlb-native-completion', 'ml.storage.cache_disk' => 'mlb-native-completion-cache',
        'mlb_ml.bundle.staging_directory' => $this->nativeRoot.'/staging']);
    $this->nativeSources = [];
});

afterEach(function () {
    DB::setDefaultConnection($this->nativeDefault);
    foreach ([$this->nativeDefault, 'completion_peer'] as $name) {
        $connection = DB::connection($name);
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('completion_peer');
    foreach ($this->nativeSources as $source) {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
    File::deleteDirectory($this->nativeRoot);
});

function nativeCompleteSeasonSource($test, int $season, string $label): array
{
    // Independent capture jobs must not share the recorder's cached batch run and hide cohort contention.
    app()->forgetInstance(ModelRunRecorder::class);
    $test->travelTo(Carbon::parse($season.'-06-27T06:00:00Z'));
    $source = ObservedMlbDatasetFixture::create(retainDataset: true, gameDate: $season.'-06-28', season: $season,
        exportSeasons: [$season], finalizedAt: Carbon::parse($season.'-06-30T06:00:00Z'));
    $test->nativeSources[] = $source;
    $test->travelTo(Carbon::parse($season.'-06-30T06:00:00Z'));

    return $source;
}

function prepareNativeOwnedCompletion($test, array $source, string $label): array
{
    expect(array_column($source['proof']['references'], 'game_id'))->toBe([$source['game']->id]);
    $specification = ['model_type' => 'mlb_tabular_bundle', 'model_version' => 'mlb-tabular-v1', 'feature_version' => 'mlb-pregame-ml-v1'];
    $cycle = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'weekly_training_cycle',
        'model_version' => $specification['model_version'], 'feature_version' => $specification['feature_version'],
        'blend_version' => 'automated-weekly-v1', 'config_hash' => hash('sha256', 'synthetic-'.$label),
        'status' => 'running', 'started_at' => now(), 'metadata' => ['dataset_path' => $source['path'],
            'dataset_hash' => $source['proof']['dataset_sha256'], 'training_fingerprint' => hash('sha256', $label),
            'training_specification' => $specification]]);
    $ids = match ($label) {
        'first' => ['30000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000101', '10000000-0000-4000-8000-000000000201'],
        'second' => ['b0000000-0000-4000-8000-000000000002', 'f0000000-0000-4000-8000-000000000102', 'f0000000-0000-4000-8000-000000000202'],
        default => null,
    };
    if ($ids !== null) {
        Str::createUuidsUsingSequence([Uuid::fromString($ids[0]), Uuid::fromString($ids[1])]);
    }
    try {
        $attempt = app(TrainingAttemptService::class)->claim($cycle->id, 300);
    } finally {
        if ($ids !== null) {
            Str::createUuidsNormally();
        }
    }
    $fixture = SyntheticMlbTabularBundleFixture::create($test->nativeRoot.'/'.$label, $source['path'], $attempt->python_run_id, $ids[2] ?? null);
    // Pure synthetic gate math, never model fitting or an empirical quality claim.
    $reportPath = $fixture['run_directory'].'/evaluation.json';
    $report = json_decode(File::get($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $report['delta_convention'] = 'baseline_minus_challenger';
    $report['rolling_weekly']['windows'] = array_fill(0, 3, ['baseline_brier' => 0.25, 'challenger_brier' => 0.20,
        'baseline_log_loss' => 0.70, 'challenger_log_loss' => 0.60, 'brier_delta' => 0.05, 'log_loss_delta' => 0.10, 'spread_mae_delta' => 0.5, 'total_mae_delta' => 0.5]);
    File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));
    $manifestPath = $fixture['run_directory'].'/manifest.json';
    $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['artifacts']['evaluation.json'] = ['sha256' => hash_file('sha256', $reportPath), 'bytes' => filesize($reportPath)];
    File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $service = app(MlbTrainingCompletion::class);
    $prepared = $service->prepare($cycle, $attempt, $fixture['run_directory'], $source['path'], hash('sha256', 'output-'.$label));
    expect($prepared->decision['eligible'])->toBeTrue()->and($prepared->decision['eligible_markets'])->toHaveCount(3);
    foreach (['artifact_path', 'dataset_path', 'evaluation_report_path'] as $field) {
        // The fixture cache root must fit the existing path columns; never rely on SQL truncation.
        expect(strlen($prepared->registration->registration->artifactAttributes[$field]))->toBeLessThanOrEqual(255);
    }

    return compact('source', 'cycle', 'attempt', 'fixture', 'service', 'prepared');
}

it('serializes actual disjoint-source completions and admits an exact retry after contention', function () {
    // Separate both new target primary-key ranges; an unrelated PK gap must not masquerade as the MLB sport predicate.
    $sentinelRun = '80000000-0000-4000-8000-000000000301';
    $sentinelArtifact = '80000000-0000-4000-8000-000000000401';
    ModelRun::create(['id' => $sentinelRun, 'sport' => 'nfl', 'run_type' => 'prediction', 'model_version' => 'synthetic',
        'feature_version' => 'synthetic', 'blend_version' => 'synthetic', 'config_hash' => hash('sha256', 'sentinel'), 'status' => 'completed', 'started_at' => now()]);
    DB::table('model_artifacts')->insert(['id' => $sentinelArtifact, 'training_run_id' => $sentinelRun, 'sport' => 'nfl',
        'market_type' => 'multi_market', 'model_type' => 'synthetic-only', 'model_version' => 'synthetic', 'feature_version' => 'synthetic',
        'dataset_hash' => hash('sha256', 'sentinel'), 'artifact_hash' => hash('sha256', $sentinelArtifact), 'artifact_path' => 'synthetic-no-file', 'status' => 'retired']);
    $sentinelBefore = ModelArtifact::findOrFail($sentinelArtifact)->getAttributes();
    $sourceA = nativeCompleteSeasonSource($this, 2025, 'source-first');
    $sourceB = nativeCompleteSeasonSource($this, 2026, 'source-second');
    $first = prepareNativeOwnedCompletion($this, $sourceA, 'first');
    DB::setDefaultConnection('completion_peer');
    try {
        $second = prepareNativeOwnedCompletion($this, $sourceB, 'second');
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    foreach (['game_id', 'prediction_id', 'snapshot_id', 'evaluation_id', 'model_run_id'] as $field) {
        expect(array_intersect(array_column($first['source']['proof']['references'], $field),
            array_column($second['source']['proof']['references'], $field)))->toBe([]);
    }
    $blocked = false;
    Event::listen('eloquent.creating: '.ModelArtifact::class, function (ModelArtifact $artifact) use ($first, $second, &$blocked) {
        if ($artifact->id !== $first['fixture']['artifact_id']) {
            return;
        }
        DB::setDefaultConnection('completion_peer');
        try {
            $second['service']->commit($second['prepared']);
            test()->fail('Competing actual completion bypassed the held cohort range.');
        } catch (Throwable $exception) {
            // Laravel may wrap a nested transaction's native timeout in DeadlockException.
            $query = $exception;
            for ($depth = 0; ! $query instanceof QueryException && $depth < 8; $depth++) {
                $query = $query->getPrevious();
                if ($query === null) {
                    throw $exception;
                }
            }
            expect($query)->toBeInstanceOf(QueryException::class);
            expect((int) ($query->errorInfo[1] ?? 0))->toBe(1205);
            expect($query->getConnectionName())->toBe('completion_peer');
            // Empty-range gap locks may coexist; the conflicting insert must refuse, not the prior SELECT.
            expect(strtolower($query->getSql()))->toStartWith('insert into `model_artifacts`');
            $blocked = true;
        } finally {
            DB::setDefaultConnection($this->nativeDefault);
        }
    });
    $receiptA = $first['service']->commit($first['prepared']);
    expect($blocked)->toBeTrue()->and(ModelArtifact::where('sport', 'mlb')->count())->toBe(1)
        ->and(ModelRun::whereKey($second['attempt']->python_run_id)->exists())->toBeFalse()
        ->and(ModelArtifact::whereKey($second['fixture']['artifact_id'])->exists())->toBeFalse()
        ->and(ModelTrainingAttempt::findOrFail($second['attempt']->id)->status)->toBe('running');
    DB::setDefaultConnection('completion_peer');
    try {
        $receiptB = $second['service']->commit($second['prepared']);
        $retry = $second['service']->commit($second['prepared']);
        $expected = $receiptB;
        ksort($retry);
        ksort($expected);
        expect($retry)->toBe($expected);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    expect($receiptA['artifact_id'])->not->toBe($receiptB['artifact_id'])
        ->and(ModelArtifact::where('sport', 'mlb')->count())->toBe(2)
        ->and(ModelArtifact::findOrFail($sentinelArtifact)->getAttributes())->toBe($sentinelBefore)
        ->and(ModelRun::where('sport', 'mlb')->where('run_type', 'training')->count())->toBe(2)
        ->and(ModelTrainingAttempt::whereIn('id', [$first['attempt']->id, $second['attempt']->id])->where('status', 'succeeded')->count())->toBe(2)
        ->and(ModelArtifact::where('sport', 'mlb')->get()->filter(fn (ModelArtifact $artifact) => data_get($artifact->metrics, 'shadow_selection.active') === true)->count())->toBe(1)
        ->and($receiptB['shadow_artifact_id'])->toBe($receiptA['artifact_id'])
        ->and(ModelRun::findOrFail($receiptA['model_run_id'])->started_at->getTimestamp())->toBe(Carbon::parse('2026-06-30T06:00:00Z')->getTimestamp());
});

it('refuses source ownership after a same-PDO commit and reopened transaction', function () {
    $fixture = prepareNativeOwnedCompletion($this, nativeCompleteSeasonSource($this, 2026, 'source-owner-input'), 'source-owner');
    $connection = DB::connection();
    $before = ModelArtifact::count();
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['source']['proof'], function () use ($connection, $fixture) {
        $connection->commit();
        $connection->beginTransaction();
        MlbTabularSourceFence::assertOwned($connection, $fixture['source']['proof']);
    }))->toThrow(RuntimeException::class);
    expect(ModelArtifact::count())->toBe($before)
        ->and(ModelTrainingAttempt::findOrFail($fixture['attempt']->id)->status)->toBe('running');
});

it('admits current native sources through the actual write PDO without resolving an unused replica', function () {
    $fixture = prepareNativeOwnedCompletion($this, nativeCompleteSeasonSource($this, 2026, 'primary-source-input'), 'primary-source');
    $connection = DB::connection();
    $connection->setReadPdo(fn () => throw new LogicException('Unused native replica resolved.'));
    $result = app(MlbTabularSourceFence::class)->run($fixture['source']['proof'], function () use ($connection, $fixture) {
        return (new MlbArtifactCohortSerializer)->run($connection, function () use ($connection, $fixture) {
            MlbTabularSourceFence::assertOwned($connection, $fixture['source']['proof']);

            return 'admitted';
        });
    });
    expect($result)->toBe('admitted');
});

it('returns current cohort state despite an old repeatable-read view', function (string $change) {
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'prediction',
        'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'synthetic',
        'config_hash' => hash('sha256', 'state-view'), 'status' => 'completed', 'started_at' => now()]);
    $id = (string) Str::uuid();
    $row = ['id' => $id, 'training_run_id' => $run->id, 'sport' => 'mlb', 'market_type' => 'multi_market',
        'model_type' => 'synthetic-only', 'model_version' => 'synthetic', 'feature_version' => 'synthetic',
        'dataset_hash' => hash('sha256', 'state-view'), 'artifact_path' => 'synthetic-no-file', 'artifact_hash' => hash('sha256', $id),
        'status' => 'challenger', 'metrics' => '{"v":0}'];
    $connection = DB::connection();
    if ($change === 'same-size-metrics') {
        $connection->table('model_artifacts')->insert($row);
    }
    $connection->beginTransaction();
    // This deliberately establishes a consistent read view before the peer's committed change.
    $connection->table('model_artifacts')->where('sport', 'mlb')->get(['id', 'metrics']);
    if ($change === 'insertion') {
        DB::connection('completion_peer')->table('model_artifacts')->insert([...$row, 'metrics' => '{"v":1}']);
    } else {
        DB::connection('completion_peer')->table('model_artifacts')->where('id', $id)->update(['metrics' => '{"v":1}']);
    }
    $rows = (new MlbArtifactCohortSerializer)->run($connection, fn () => (new MlbArtifactCohortState)->read($connection));
    expect($rows)->toHaveCount(1)->and($rows[0]->metrics)->toBe(['v' => 1]);
})->with(['insertion', 'same-size-metrics']);
