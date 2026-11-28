<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    foreach (['dataset-source', 'dataset-object', 'dataset-old', 'dataset-new'] as $disk) {
        Storage::fake($disk);
    }
    config(['ml.storage.disk' => 'dataset-object', 'ml.storage.cache_disk' => 'dataset-old']);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});
function datasetBoundaryRun(): ModelRun
{
    return ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'nfl', 'run_type' => 'training', 'status' => 'completed',
        'model_version' => 'dataset-v1', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'dataset'), 'started_at' => now(), 'metadata' => ['sentinel' => 'keep']]);
}
function datasetBoundaryArtifact(bool $attached = true): ModelArtifact
{
    $source = Storage::disk('dataset-source');
    $source->put('model.json', '{"synthetic":true}');
    $source->put('dataset.csv', "x,target\n0,0\n1,1\n");
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), datasetBoundaryRun(), 'win_probability',
        'nfl_full_historical_profile', 'dataset-v1', 'synthetic', hash_file('sha256', $source->path('dataset.csv')),
        $source->path('model.json'), [], $attached ? $source->path('dataset.csv') : null);
    config(['ml.storage.cache_disk' => 'dataset-new']);

    return $artifact->refresh();
}
function datasetBoundaryRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
}
function datasetBoundaryFailure(callable $work): ?Throwable
{
    try {
        $work();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('refuses stale dataset owners before materialization or attach SQL', function (bool $attach) {
    $artifact = datasetBoundaryArtifact(! $attach);
    $new = datasetBoundaryRun();
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $new->id]);
    $rows = datasetBoundaryRows();
    $failure = datasetBoundaryFailure(fn () => $attach ? app(ModelArtifactRegistry::class)->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')) : app(ModelArtifactRegistry::class)->materializeDataset($artifact));
    expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with([false, true]);

it('refuses owner changes during actual dataset IO', function (bool $attach) {
    $artifact = datasetBoundaryArtifact(! $attach);
    $new = datasetBoundaryRun();
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $after = null;
    $method = $attach ? 'storeDataset' : 'materialize';
    $storage->shouldReceive($method)->once()->andReturnUsing(function (...$args) use ($real, $method, $artifact, $new, &$after) {
        $result = $real->$method(...$args);
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $new->id]);
        $after = datasetBoundaryRows();

        return $result;
    });
    $registry = new ModelArtifactRegistry($storage);
    $failure = datasetBoundaryFailure(fn () => $attach ? $registry->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')) : $registry->materializeDataset($artifact));
    expect($after)->not->toBeNull()->and(datasetBoundaryRows())->toBe($after)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with([false, true]);

it('refuses cancelled dataset pointer persistence without partial run metadata', function (bool $attach) {
    $artifact = datasetBoundaryArtifact(! $attach);
    $rows = datasetBoundaryRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function () use (&$attempts) {
        $attempts++;

        return false;
    });
    try {
        $failure = datasetBoundaryFailure(fn () => $attach ? app(ModelArtifactRegistry::class)->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')) : app(ModelArtifactRegistry::class)->materializeDataset($artifact));
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($attempts)->toBe(1)->and(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with([false, true]);

it('does not persist a dataset cache pointer in the explicit read only role', function () {
    $artifact = datasetBoundaryArtifact();
    $rows = datasetBoundaryRows();
    $path = app(ModelArtifactRegistry::class)->materializeDataset($artifact, false);
    expect(is_file($path))->toBeTrue()->and(hash_file('sha256', $path))->toBe($artifact->dataset_hash)->and(datasetBoundaryRows())->toBe($rows);
});

it('preserves successful generic attachment metadata and repeated content reads', function () {
    $artifact = datasetBoundaryArtifact(false);
    $registry = app(ModelArtifactRegistry::class);
    $result = $registry->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv'));
    expect($result->id)->toBe($artifact->id)->and($result->dataset_hash)->toBe(hash_file('sha256', Storage::disk('dataset-source')->path('dataset.csv')))
        ->and(data_get(ModelRun::find($artifact->training_run_id)->metadata, 'sentinel'))->toBe('keep');
    $path = $registry->materializeDataset($result);
    expect($registry->materializeDataset($result))->toBe($path);
});

it('rolls back an attachment when its training metadata save is cancelled', function () {
    $artifact = datasetBoundaryArtifact(false);
    $rows = datasetBoundaryRows();
    $dispatcher = ModelRun::getEventDispatcher();
    ModelRun::setEventDispatcher(clone $dispatcher);
    ModelRun::saving(fn () => false);
    try {
        $failure = datasetBoundaryFailure(fn () => app(ModelArtifactRegistry::class)->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')));
    } finally {
        ModelRun::setEventDispatcher($dispatcher);
    }
    expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('rolls back a post-save artifact owner change before returning a dataset path', function () {
    $artifact = datasetBoundaryArtifact();
    $other = datasetBoundaryRun();
    $rows = datasetBoundaryRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $reached = false;
    ModelArtifact::saved(function ($model) use ($other, &$reached) {
        $reached = true;
        DB::table('model_artifacts')->where('id', $model->id)->update(['training_run_id' => $other->id]);
    });
    try {
        $failure = datasetBoundaryFailure(fn () => app(ModelArtifactRegistry::class)->materializeDataset($artifact));
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue()->and(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses oversized dataset descriptors before storage IO', function () {
    $artifact = datasetBoundaryArtifact();
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['dataset_object_key' => str_repeat('x', 4097)]);
    $artifact->refresh();
    $rows = datasetBoundaryRows();
    $storage = Mockery::mock(MlArtifactStorage::class);
    $storage->shouldNotReceive('materialize');
    $failure = datasetBoundaryFailure(fn () => (new ModelArtifactRegistry($storage))->materializeDataset($artifact));
    expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses symlinked legacy dataset sources without updating SQL', function () {
    $artifact = datasetBoundaryArtifact();
    $path = Storage::disk('dataset-source')->path('dataset.csv');
    $link = $path.'.link';
    symlink($path, $link);
    try {
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['dataset_disk' => null, 'dataset_object_key' => null, 'dataset_uri' => null, 'dataset_path' => $link]);
        $artifact->refresh();
        $rows = datasetBoundaryRows();
        $failure = datasetBoundaryFailure(fn () => app(ModelArtifactRegistry::class)->materializeDataset($artifact));
        expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
    } finally {
        unlink($link);
    }
});

it('preserves unrelated metadata and checks exact bounded dataset bytes before read only return', function () {
    $artifact = datasetBoundaryArtifact();
    $rows = datasetBoundaryRows();
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact) {
        expect($args[4])->toBe((int) $artifact->dataset_size);

        return $real->materialize(...$args);
    });
    $path = (new ModelArtifactRegistry($storage))->materializeDataset($artifact, false, (int) $artifact->dataset_size);
    expect(hash_file('sha256', $path))->toBe($artifact->dataset_hash)->and(datasetBoundaryRows())->toBe($rows);
});

it('uses bounded metadata projection for atomic attachment without dropping unrelated keys', function () {
    $artifact = datasetBoundaryArtifact(false);
    $run = ModelRun::findOrFail($artifact->training_run_id);
    $run->update(['metadata' => ['sentinel' => 'keep', 'nested' => ['zero' => 0, 'null' => null, 'empty' => []]]]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'model_runs')) {
            $queries[] = $query->sql;
        }
    });
    app(ModelArtifactRegistry::class)->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv'));
    $bounded = array_filter($queries, fn ($sql) => str_contains($sql, '2097152') && str_contains($sql, 'AS metadata'));
    expect(count($bounded))->toBe(2)->and(collect($queries)->filter(fn ($sql) => preg_match('/select\s+\*/i', $sql))->count())->toBe(0);
    $metadata = ModelRun::findOrFail($artifact->training_run_id)->metadata;
    expect($metadata['sentinel'])->toBe('keep')->and($metadata['nested'])->toBe(['zero' => 0, 'null' => null, 'empty' => []]);
});

it('retains the initial training owner status across canonical attachment IO', function () {
    $artifact = datasetBoundaryArtifact();
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $after = null;
    $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact, &$after) {
        $path = $real->materialize(...$args);
        DB::table('model_runs')->where('id', $artifact->training_run_id)->update(['status' => 'failed']);
        $after = datasetBoundaryRows();

        return $path;
    });
    $failure = datasetBoundaryFailure(fn () => (new ModelArtifactRegistry($storage))->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')));
    expect($after)->not->toBeNull()->and(datasetBoundaryRows())->toBe($after)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses a saving observer changing the requested dataset cache pointer', function () {
    $artifact = datasetBoundaryArtifact();
    $rows = datasetBoundaryRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    ModelArtifact::saving(function ($row) {
        $row->dataset_path = 'synthetic-unrequested-path';
    });
    try {
        $failure = datasetBoundaryFailure(fn () => app(ModelArtifactRegistry::class)->materializeDataset($artifact));
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses a saving observer dropping intended dataset owner metadata keys', function () {
    $artifact = datasetBoundaryArtifact(false);
    $rows = datasetBoundaryRows();
    $dispatcher = ModelRun::getEventDispatcher();
    ModelRun::setEventDispatcher(clone $dispatcher);
    ModelRun::saving(function ($row) {
        $row->metadata = ['sentinel' => 'changed-not-preserved'];
    });
    try {
        $failure = datasetBoundaryFailure(fn () => app(ModelArtifactRegistry::class)->attachDataset($artifact, Storage::disk('dataset-source')->path('dataset.csv')));
    } finally {
        ModelRun::setEventDispatcher($dispatcher);
    }
    expect(datasetBoundaryRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});
