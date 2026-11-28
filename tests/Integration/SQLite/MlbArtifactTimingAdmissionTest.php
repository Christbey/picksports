<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTabularModelInferenceService;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Functional range collaborator only: current source and transaction admission remain real.
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Storage::fake('mlb-artifact-admission-source');
    Storage::fake('mlb-artifact-admission-cache');
    config()->set('ml.storage.disk', 'mlb-artifact-admission-source');
    config()->set('ml.storage.cache_disk', 'mlb-artifact-admission-cache');
    $this->artifactAdmissionRoot = storage_path('framework/testing/mlb-artifact-admission-'.Str::uuid());
    config()->set('mlb_ml.bundle.staging_directory', $this->artifactAdmissionRoot.'/staging');
});

afterEach(function () {
    File::deleteDirectory($this->artifactAdmissionRoot);
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function mlbArtifactTimingFixture(string $root): ModelArtifact
{
    $source = ObservedMlbDatasetFixture::create(true);
    try {
        $fixture = SyntheticMlbTabularBundleFixture::create($root.'/run', $source['path']);

        return app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    } finally {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
}

it('requires current owned artifact timing admission before inference materialization', function (string $drift) {
    $artifact = mlbArtifactTimingFixture($this->artifactAdmissionRoot);
    $run = ModelRun::findOrFail($artifact->training_run_id);
    if ($drift === 'unmarked') {
        $metadata = $run->metadata;
        unset($metadata['mlb_tabular_admission']);
        DB::table('model_runs')->where('id', $run->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    } elseif ($drift === 'binding') {
        DB::table('model_runs')->where('id', $run->id)->update(['code_version' => 'changed-current-code']);
    } elseif ($drift === 'source') {
        $id = $run->metadata['mlb_tabular_admission']['dataset']['references'][0]['game_id'];
        DB::table('mlb_games')->where('id', $id)->update(['home_score' => 99]);
    } else {
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['model_type' => 'generic_model']);
        $artifact->refresh();
    }
    $calls = 0;
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldReceive('materializeArtifact')->zeroOrMoreTimes()->andReturnUsing(function () use (&$calls) {
        $calls++;
        throw new LogicException('Reached materialization before current timing admission.');
    });
    $service = new MlbTabularModelInferenceService($registry, app(MlbTabularModelBundle::class));
    expect(fn () => $service->predict($artifact, ['elo_diff' => 1]))->toThrow(RuntimeException::class)
        ->and($calls)->toBe(0);
})->with(['unmarked', 'binding', 'source', 'wrong_type']);

/** Instrumented synthetic backend stream; body contents are never logged. */
final class MlbBoundedStoredStream
{
    public $context;

    public static string $body = '';

    public static int $readBytes = 0;

    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read($count): string
    {
        $bytes = substr(self::$body, $this->position, $count);
        $this->position += strlen($bytes);
        self::$readBytes += strlen($bytes);

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_stat(): array
    {
        return ['size' => strlen(self::$body)];
    }
}

it('bounds registered artifact backend reads by their owned recorded size before cache writes', function (string $case) {
    $artifact = mlbArtifactTimingFixture($this->artifactAdmissionRoot);
    $expected = (int) $artifact->artifact_size;
    $cache = Storage::fake('mlb-counted-object-cache');
    config()->set('ml.storage.cache_disk', 'mlb-counted-object-cache');
    MlbBoundedStoredStream::$body = str_repeat('x', $expected + 65536);
    MlbBoundedStoredStream::$readBytes = 0;
    expect(stream_wrapper_register('mlbcountedobject', MlbBoundedStoredStream::class))->toBeTrue();
    try {
        $source = Mockery::mock(FilesystemAdapter::class);
        $source->shouldReceive('exists')->with($artifact->artifact_object_key)->andReturnTrue();
        $source->shouldReceive('size')->zeroOrMoreTimes()->with($artifact->artifact_object_key)
            ->andReturn($case === 'growth_after_metadata' ? $expected : strlen(MlbBoundedStoredStream::$body));
        $source->shouldReceive('readStream')->zeroOrMoreTimes()->with($artifact->artifact_object_key)
            ->andReturnUsing(function () {
                $stream = fopen('mlbcountedobject://synthetic', 'rb');
                // A one-byte backend chunk exposes logical overreads without PHP wrapper prefetch noise.
                stream_set_chunk_size($stream, 1);

                return $stream;
            });
        Storage::shouldReceive('disk')->with($artifact->artifact_disk)->andReturn($source);
        Storage::shouldReceive('disk')->with('mlb-counted-object-cache')->andReturn($cache);
        $registry = new ModelArtifactRegistry(new MlArtifactStorage);
        expect(fn () => $registry->materializeArtifact($artifact, false, 268435456))->toThrow(RuntimeException::class);
        expect(MlbBoundedStoredStream::$readBytes)->toBeLessThanOrEqual($expected + 1);
        if ($case === 'growth_after_metadata') {
            expect(MlbBoundedStoredStream::$readBytes)->toBe($expected + 1);
        }
    } finally {
        stream_wrapper_unregister('mlbcountedobject');
        MlbBoundedStoredStream::$body = '';
    }
})->with(['advertised_mismatch', 'growth_after_metadata']);
