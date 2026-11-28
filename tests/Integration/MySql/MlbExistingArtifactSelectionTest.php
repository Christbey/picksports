<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\ML\TrainingAttemptService;
use App\Services\MLB\MlbExistingArtifactMutation;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

function selectorNativeSource($test, int $season, string $label): array
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

function selectorNativeCompletion($test, array $source, string $label): array
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

function selectorNativeArtifact($test, int $season, string $label): ModelArtifact
{
    $prepared = selectorNativeCompletion($test, selectorNativeSource($test, $season, $label), $label);
    $receipt = $prepared['service']->commit($prepared['prepared']);

    return ModelArtifact::findOrFail($receipt['artifact_id']);
}

function selectorNativeRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
}

it('selects and deselects genuinely qualified native MLB artifacts without changing protected runs', function () {
    $first = selectorNativeArtifact($this, 2025, 'first');
    $second = selectorNativeArtifact($this, 2026, 'second');
    $runs = selectorNativeRows()[1];
    $selector = app(ShadowArtifactSelector::class);
    $selector->activateChallenger($second, ['source' => 'synthetic-native-selector']);
    expect(data_get($second->metrics, 'shadow_selection.active'))->toBeTrue()
        ->and(data_get($first->fresh()->metrics, 'shadow_selection.active'))->toBeFalse();
    $selector->activateChallenger($first);
    expect(data_get($first->metrics, 'shadow_selection.active'))->toBeTrue()
        ->and(data_get($second->fresh()->metrics, 'shadow_selection.active'))->toBeFalse();
    $selector->deactivateChallenger($first, 'synthetic-native-done');
    expect(data_get($first->metrics, 'shadow_selection.active'))->toBeFalse()
        ->and(data_get($first->metrics, 'shadow_selection.deactivation_reason'))->toBe('synthetic-native-done')
        ->and(selectorNativeRows()[1])->toBe($runs);
});

it('refuses changed prepared native target ownership status family or source before any selector writes', function () {
    $artifact = selectorNativeArtifact($this, 2026, 'changed');
    $service = app(MlbExistingArtifactMutation::class);
    foreach (['status' => 'retired', 'model_type' => 'mlb_period_bundle', 'training_run_id' => (string) Str::uuid()] as $field => $value) {
        $prepared = $service->prepareSelection($artifact->fresh(), 'activate', ['metadata' => []]);
        $old = DB::table('model_artifacts')->where('id', $artifact->id)->value($field);
        // Existing arbitrary trusted SQL is used only to model independent persisted drift.
        if ($field === 'training_run_id') {
            $run = ModelRun::create(['id' => $value, 'sport' => 'mlb', 'run_type' => 'synthetic_unqualified', 'model_version' => 'synthetic',
                'feature_version' => 'synthetic', 'blend_version' => 'none', 'config_hash' => hash('sha256', 'synthetic'), 'status' => 'completed', 'started_at' => now()]);
        }
        DB::table('model_artifacts')->where('id', $artifact->id)->update([$field => $value]);
        $before = selectorNativeRows();
        expect(fn () => $service->commit($prepared))->toThrow(RuntimeException::class);
        expect(selectorNativeRows())->toBe($before);
        DB::table('model_artifacts')->where('id', $artifact->id)->update([$field => $old]);
    }
});

it('rolls back native peer and target selection when the selected save is cancelled', function () {
    $first = selectorNativeArtifact($this, 2025, 'first');
    $second = selectorNativeArtifact($this, 2026, 'second');
    $selector = app(ShadowArtifactSelector::class);
    $selector->activateChallenger($first);
    $before = selectorNativeRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $peerWrites = $cancelled = 0;
    ModelArtifact::saved(function (ModelArtifact $row) use ($first, &$peerWrites) {
        if ($row->id === $first->id) {
            $peerWrites++;
        }
    });
    ModelArtifact::saving(function (ModelArtifact $row) use ($second, &$cancelled) {
        if ($row->id === $second->id) {
            $cancelled++;

            return false;
        }
    });
    try {
        expect(fn () => $selector->activateChallenger($second))->toThrow(RuntimeException::class, 'cancelled');
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($peerWrites)->toBe(1)->and($cancelled)->toBe(1)->and(selectorNativeRows())->toBe($before);
});

it('detaches native prepared selection metadata and refuses source drift before mutation', function () {
    $artifact = selectorNativeArtifact($this, 2026, 'context');
    $service = app(MlbExistingArtifactMutation::class);
    $value = 'captured';
    $payload = ['metadata' => ['label' => &$value]];
    $prepared = $service->prepareSelection($artifact, 'activate', $payload);
    $value = str_repeat('x', 70000);
    $service->commit($prepared);
    expect(data_get($artifact->fresh()->metrics, 'shadow_selection.metadata.label'))->toBe('captured');
    $preparedOwner = $service->prepareSelection($artifact->fresh(), 'deactivate', ['reason' => 'owner-must-not-write']);
    $beforeOwner = selectorNativeRows();
    DB::setDefaultConnection('completion_peer');
    try {
        expect(fn () => $service->commit($preparedOwner))->toThrow(RuntimeException::class);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    $connection = DB::connection();
    $originalPdo = $connection->getPdo();
    $connection->setPdo(DB::connection('completion_peer')->getPdo());
    try {
        expect(fn () => $service->commit($preparedOwner))->toThrow(RuntimeException::class);
    } finally {
        $connection->setPdo($originalPdo);
    }
    expect(selectorNativeRows())->toBe($beforeOwner);
    $prepared = $service->prepareSelection($artifact->fresh(), 'deactivate', ['reason' => 'must-not-write']);
    $gameId = $this->nativeSources[0]['game']->id;
    DB::table('mlb_games')->where('id', $gameId)->update(['status' => 'STATUS_POSTPONED']);
    $before = selectorNativeRows();
    expect(fn () => $service->commit($prepared))->toThrow(RuntimeException::class);
    expect(selectorNativeRows())->toBe($before);
});

it('routes actual collation-equivalent MLB sport identities through strict admission before peer writes', function () {
    $first = selectorNativeArtifact($this, 2025, 'first');
    $target = selectorNativeArtifact($this, 2026, 'second');
    $selector = app(ShadowArtifactSelector::class);
    $selector->activateChallenger($first);
    DB::table('model_artifacts')->where('id', $target->id)->update(['sport' => 'mĺb']);
    $target->refresh();
    expect($target->getRawOriginal('sport'))->toBe('mĺb');
    $matches = DB::table('model_artifacts')->where('id', $target->id)->selectRaw('CASE WHEN sport = ? THEN 1 ELSE 0 END AS matches_mlb', ['mlb'])->value('matches_mlb');
    expect((int) $matches)->toBe(1)->and(data_get($first->fresh()->metrics, 'shadow_selection.active'))->toBeTrue();
    $before = selectorNativeRows();
    $failure = null;
    try {
        $selector->activateChallenger($target);
    } catch (RuntimeException $error) {
        $failure = $error;
    }
    expect(selectorNativeRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('retires active unqualified peers in the actual tabular family and status SQL predicate', function (string $field, string $value) {
    $target = selectorNativeArtifact($this, 2026, 'qualified');
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'synthetic_unqualified',
        'model_version' => 'unqualified', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'unqualified'), 'status' => 'completed', 'started_at' => now()]);
    $peer = ModelArtifact::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'training_run_id' => $run->id,
        'model_type' => 'mlb_tabular_bundle', 'market_type' => 'multi_market', 'model_version' => 'explicit-unqualified-peer',
        'feature_version' => 'synthetic', 'dataset_hash' => hash('sha256', 'unqualified'), 'artifact_hash' => hash('sha256', 'unqualified'),
        'artifact_path' => 'explicit-unqualified-never-loaded', 'status' => 'challenger', 'metrics' => ['shadow_selection' => ['active' => true]]]);
    DB::table('model_artifacts')->where('id', $peer->id)->update([$field => $value, 'updated_at' => now()->addHour()]);
    $peer->refresh();
    expect($peer->getRawOriginal($field))->toBe($value)
        ->and(DB::table('model_artifacts')->where('id', $peer->id)->where('model_type', 'mlb_tabular_bundle')->whereIn('status', ['challenger', 'promotion_eligible'])->exists())->toBeTrue()
        ->and(data_get(ModelRun::findOrFail($run->id)->metadata, 'mlb_tabular_admission'))->toBeNull();
    $runs = selectorNativeRows()[1];
    $selector = app(ShadowArtifactSelector::class);
    $selector->activateChallenger($target);
    expect(data_get($target->metrics, 'shadow_selection.active'))->toBeTrue()
        ->and(data_get($peer->fresh()->metrics, 'shadow_selection.active'))->toBeFalse()
        ->and($selector->activeChallenger('mlb', 'mlb_tabular_bundle')->id)->toBe($target->id)
        ->and(selectorNativeRows()[1])->toBe($runs);
})->with(['family' => ['model_type', 'mlb_tabular_bundlé'], 'status' => ['status', 'CHALLENGER']]);

function selectorNativeBlocked(callable $attempt, string $connection): void
{
    try {
        $attempt();
        test()->fail('Expected source Game lock contention.');
    } catch (Throwable $failure) {
        $error = $failure;
        for ($i = 0; ! $error instanceof QueryException && $i < 12; $i++) {
            $error = $error->getPrevious();
            if ($error === null) {
                throw $failure;
            }
        }
        expect($error)->toBeInstanceOf(QueryException::class);
        expect((int) ($error->errorInfo[1] ?? 0))->toBe(1205)
            ->and($error->getConnectionName())->toBe($connection)
            ->and(strtolower($error->getSql()))->toContain('mlb_games')->toContain('for update');
    }
}

it('serializes a same-target artifact reader and selection writer at current source Games in both directions', function () {
    $artifact = selectorNativeArtifact($this, 2026, 'overlap');
    DB::setDefaultConnection('completion_peer');
    try {
        $peerWriter = app(MlbExistingArtifactMutation::class);
        $peerArtifact = ModelArtifact::findOrFail($artifact->id);
        $prepared = $peerWriter->prepareSelection($peerArtifact, 'activate', ['metadata' => []]);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    $readerBlockedWriter = false;
    DB::connection()->beforeExecuting(function ($sql) use (&$readerBlockedWriter, $peerWriter, $prepared) {
        if ($readerBlockedWriter || ! str_contains(strtolower($sql), 'from `model_artifacts`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $readerBlockedWriter = true;
        DB::setDefaultConnection('completion_peer');
        try {
            selectorNativeBlocked(fn () => $peerWriter->commit($prepared), 'completion_peer');
        } finally {
            DB::setDefaultConnection($this->nativeDefault);
        }
    });
    expect(app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact']->id)->toBe($artifact->id)
        ->and($readerBlockedWriter)->toBeTrue();
    DB::setDefaultConnection('completion_peer');
    try {
        $peerWriter->commit($prepared);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $writerBlockedReader = false;
    ModelArtifact::saving(function (ModelArtifact $row) use ($artifact, &$writerBlockedReader) {
        if ($row->id !== $artifact->id) {
            return;
        }
        $writerBlockedReader = true;
        DB::setDefaultConnection('completion_peer');
        try {
            $peer = ModelArtifact::findOrFail($artifact->id);
            selectorNativeBlocked(fn () => app(MlbTabularArtifactAdmission::class)->admit($peer), 'completion_peer');
        } finally {
            DB::setDefaultConnection($this->nativeDefault);
        }
    });
    try {
        app(ShadowArtifactSelector::class)->deactivateChallenger($artifact->fresh(), 'overlap-done');
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($writerBlockedReader)->toBeTrue();
    DB::setDefaultConnection('completion_peer');
    try {
        expect(app(MlbTabularArtifactAdmission::class)->admit(ModelArtifact::findOrFail($artifact->id))['artifact']->id)->toBe($artifact->id);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
});
