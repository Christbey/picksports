<?php

use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('refuses an allocation-unsafe metadata envelope before decoding the body during dataset attachment', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    foreach (['allocation-source', 'allocation-object', 'allocation-cache'] as $disk) {
        Storage::fake($disk);
    }
    config(['ml.storage.disk' => 'allocation-object', 'ml.storage.cache_disk' => 'allocation-cache']);
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'nfl', 'run_type' => 'training', 'status' => 'completed',
        'model_version' => 'allocation-v1', 'feature_version' => 'synthetic', 'blend_version' => 'none', 'config_hash' => hash('sha256', 'allocation'), 'started_at' => now()]);
    $source = Storage::disk('allocation-source');
    $source->put('model.json', '{"synthetic":true}');
    $source->put('dataset.csv', "x,y\n0,0\n");
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), $run, 'win_probability', 'nfl_full_historical_profile',
        'allocation-v1', 'synthetic', hash_file('sha256', $source->path('dataset.csv')), $source->path('model.json'), []);
    // SQLite constructs the large value internally, not a PHP fixture string or decoded model cast.
    DB::statement("UPDATE model_runs SET metadata = json_object('sentinel','keep','payload',replace(hex(zeroblob(25165824)),'00','a')) WHERE id = ?", [$run->id]);
    $before = DB::selectOne('SELECT length(CAST(metadata AS BLOB)) AS bytes, hex(substr(metadata,1,64)) AS prefix FROM model_runs WHERE id = ?', [$run->id]);
    fwrite(STDERR, 'ALLOCATION_FIXTURE_READY bytes='.$before->bytes.' php_peak='.memory_get_peak_usage(true)."\n");
    DB::listen(function ($query) {
        if (str_contains($query->sql, '2097152') && str_contains($query->sql, 'AS metadata')) {
            fwrite(STDERR, "ACTUAL_CONSUMER_METADATA_QUERY\n");
        }
    });
    $failure = null;
    try {
        app(ModelArtifactRegistry::class)->attachDataset($artifact, $source->path('dataset.csv'));
    } catch (Throwable $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class);
    $after = DB::selectOne('SELECT length(CAST(metadata AS BLOB)) AS bytes, hex(substr(metadata,1,64)) AS prefix FROM model_runs WHERE id = ?', [$run->id]);
    expect((array) $after)->toBe((array) $before);
});

function datasetAllocationFixture($test): array
{
    $test->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    foreach (['allocation-source', 'allocation-object', 'allocation-cache'] as $disk) {
        Storage::fake($disk);
    }
    config(['ml.storage.disk' => 'allocation-object', 'ml.storage.cache_disk' => 'allocation-cache']);
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'nfl', 'run_type' => 'training', 'status' => 'completed',
        'model_version' => 'allocation-v1', 'feature_version' => 'synthetic', 'blend_version' => 'none', 'config_hash' => hash('sha256', 'allocation'), 'started_at' => now()]);
    $source = Storage::disk('allocation-source');
    $source->put('model.json', '{"synthetic":true}');
    $source->put('dataset.csv', "x,y\n0,0\n");
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), $run, 'win_probability', 'nfl_full_historical_profile',
        'allocation-v1', 'synthetic', hash_file('sha256', $source->path('dataset.csv')), $source->path('model.json'), []);

    return [$artifact, $source, $run];
}

it('attaches near-budget metadata with a wide nested shape without dropping any keys at128M', function () {
    [$artifact, $source, $run] = datasetAllocationFixture($this);
    DB::statement("WITH RECURSIVE n(x) AS (SELECT 0 UNION ALL SELECT x+1 FROM n WHERE x<999) UPDATE model_runs SET metadata = json_set((SELECT json_group_object('k'||x,json_object('zero',0,'null',NULL)) FROM n),'$.payload',replace(hex(zeroblob(1966080)),'00','a'),'$.sentinel','keep') WHERE id = ?", [$run->id]);
    $before = DB::selectOne('SELECT length(CAST(metadata AS BLOB)) AS bytes FROM model_runs WHERE id = ?', [$run->id]);
    expect($before->bytes)->toBeLessThan(2097152)->toBeGreaterThan(1966080);
    $result = app(ModelArtifactRegistry::class)->attachDataset($artifact, $source->path('dataset.csv'));
    $values = DB::selectOne("SELECT json_extract(metadata,'$.sentinel') AS sentinel, json_extract(metadata,'$.k999.zero') AS zero, json_type(metadata,'$.k999.null') AS null_type, length(json_extract(metadata,'$.payload')) AS payload_bytes, (SELECT count(*) FROM json_each(metadata)) AS key_count FROM model_runs WHERE id = ?", [$run->id]);
    expect($result->id)->toBe($artifact->id)->and($values->sentinel)->toBe('keep')->and($values->zero)->toBe(0)
        ->and($values->null_type)->toBe('null')->and($values->payload_bytes)->toBe(1966080)->and($values->key_count)->toBe(1003);
});

it('refuses a wide over-token metadata body without changing SQL rows', function () {
    [$artifact, $source, $run] = datasetAllocationFixture($this);
    DB::statement("WITH RECURSIVE n(x) AS (SELECT 0 UNION ALL SELECT x+1 FROM n WHERE x<9999) UPDATE model_runs SET metadata = (SELECT json_group_object('k'||x,0) FROM n) WHERE id = ?", [$run->id]);
    $before = DB::selectOne('SELECT length(CAST(metadata AS BLOB)) AS bytes FROM model_runs WHERE id = ?', [$run->id]);
    expect($before->bytes)->toBeLessThan(2097152);
    expect(fn () => app(ModelArtifactRegistry::class)->attachDataset($artifact, $source->path('dataset.csv')))->toThrow(RuntimeException::class);
    expect($artifact->fresh()->dataset_disk)->toBeNull()
        ->and(DB::selectOne('SELECT length(CAST(metadata AS BLOB)) AS bytes FROM model_runs WHERE id = ?', [$run->id])->bytes)->toBe($before->bytes);
});
