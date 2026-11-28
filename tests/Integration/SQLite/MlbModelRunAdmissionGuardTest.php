<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularModelRunRegistrar;
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
    Storage::fake('mlb-run-proof-source');
    Storage::fake('mlb-run-proof-cache');
    config()->set('ml.storage.disk', 'mlb-run-proof-source');
    config()->set('ml.storage.cache_disk', 'mlb-run-proof-cache');
    $this->proofRoot = storage_path('framework/testing/mlb-run-proof-'.Str::uuid());
    config()->set('mlb_ml.bundle.staging_directory', $this->proofRoot.'/staging');
});

afterEach(function () {
    File::deleteDirectory($this->proofRoot);
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function mlbBoundProofRun(string $root): ModelRun
{
    $source = ObservedMlbDatasetFixture::create(true);
    try {
        $fixture = SyntheticMlbTabularBundleFixture::create($root.'/run', $source['path']);
        $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);

        return ModelRun::findOrFail($artifact->training_run_id);
    } finally {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
}

it('preserves recorded MLB run admission through ordinary quiet numeric and deletion methods', function (string $method) {
    $run = mlbBoundProofRun($this->proofRoot);
    if ($method === 'numeric_extra') {
        $run->updateQuietly(['status' => '1']);
    }
    $before = $run->getAttributes();
    $metadata = $run->metadata;
    $metadata['mlb_tabular_admission']['forged'] = true;
    expect(fn () => match ($method) {
        'save' => $run->fill(['metadata' => $metadata])->save(),
        'saveQuietly' => $run->fill(['metadata' => $metadata])->saveQuietly(),
        'updateQuietly' => $run->updateQuietly(['metadata' => []]),
        'numeric_extra' => $run->increment('status', 0, ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]),
        'numeric_json' => $run->increment('metadata->mlb_tabular_admission->rows'),
        'delete' => $run->delete(),
        'deleteQuietly' => $run->deleteQuietly(),
        'forceDelete' => $run->forceDelete(),
    })->toThrow(LogicException::class);
    expect($run->fresh()->getAttributes())->toBe($before);
})->with(['save', 'saveQuietly', 'updateQuietly', 'numeric_extra', 'numeric_json', 'delete', 'deleteQuietly', 'forceDelete']);

it('does not certify an existing unmarked run by copying another run admission', function (bool $quiet) {
    $owned = mlbBoundProofRun($this->proofRoot);
    $legacy = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'training',
        'model_version' => 'legacy', 'feature_version' => 'legacy', 'blend_version' => 'legacy', 'config_hash' => hash('sha256', 'legacy'),
        'status' => 'completed', 'started_at' => now(), 'metadata' => ['legacy' => true]]);
    $before = $legacy->refresh()->getAttributes();
    $patch = ['metadata' => ['mlb_tabular_admission' => $owned->metadata['mlb_tabular_admission']]];
    expect(fn () => $quiet ? $legacy->updateQuietly($patch) : $legacy->update($patch))->toThrow(LogicException::class);
    expect($legacy->fresh()->getAttributes())->toBe($before);
})->with([false, true]);

it('does not grant admission to an unowned new run or an unsaved numeric instance', function (string $operation) {
    $owned = mlbBoundProofRun($this->proofRoot);
    $before = ModelRun::count();
    if ($operation === 'copy_new') {
        $attributes = $owned->getAttributes();
        unset($attributes['created_at'], $attributes['updated_at']);
        $attributes['id'] = (string) Str::uuid();
        $copy = new ModelRun;
        $copy->setRawAttributes($attributes);
        expect(fn () => $copy->save())->toThrow(LogicException::class);
    } else {
        $rows = DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        expect(fn () => (new ModelRun)->increment('status'))->toThrow(LogicException::class);
        expect(DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($rows);
    }
    expect(ModelRun::count())->toBe($before);
})->with(['copy_new', 'unsaved_numeric']);

it('preserves other model run metadata and supported lifecycle changes', function () {
    $run = mlbBoundProofRun($this->proofRoot);
    $proof = $run->metadata['mlb_tabular_admission'];
    $run->updateQuietly(['status' => 'failed', 'metadata' => [...$run->metadata, 'operator_note' => 'Synthetic lifecycle control.']]);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->metadata['mlb_tabular_admission'])->toBe($proof)
        ->and($run->fresh()->metadata['operator_note'])->toBe('Synthetic lifecycle control.');
});

it('supports bounded leaf admission without fetching unrelated legacy body columns', function (string $field) {
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'nfl', 'run_type' => 'weekly_training_cycle',
        'model_version' => 'legacy', 'feature_version' => 'legacy', 'blend_version' => 'legacy', 'config_hash' => hash('sha256', 'legacy'),
        'status' => 'running', 'started_at' => now(), 'metadata' => []]);
    // A retained synthetic legacy body, not a newly certified row; parameters must never be selected by the guard.
    DB::table('model_runs')->where('id', $run->id)->update([$field => json_encode(['unrelated' => str_repeat('x', 8 * 1024 * 1024)])]);
    $minimal = ModelRun::query()->select(['id', 'sport', 'run_type', 'status'])->findOrFail($run->id);
    DB::enableQueryLog();
    try {
        $minimal->updateQuietly(['status' => 'failed']);
        expect(DB::table('model_runs')->where('id', $run->id)->value('status'))->toBe('failed');
        $selects = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select'));
        expect($selects->contains(fn ($query) => preg_match('/select\s+\*/i', $query['query']) || str_contains($query['query'], '"parameters"')))->toBeFalse();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with(['parameters', 'metadata']);

it('rechecks recorded bindings and proof content from the current leaf row', function (string $drift) {
    $run = mlbBoundProofRun($this->proofRoot);
    if ($drift === 'binding') {
        DB::table('model_runs')->where('id', $run->id)->update(['code_version' => 'changed-current-code']);
    } else {
        $metadata = $run->metadata;
        $metadata['mlb_tabular_admission']['dataset']['forged'] = true;
        DB::table('model_runs')->where('id', $run->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }
    $before = DB::table('model_runs')->where('id', $run->id)->first();
    expect(fn () => $run->updateQuietly(['status' => 'failed']))->toThrow(LogicException::class);
    expect((array) DB::table('model_runs')->where('id', $run->id)->first())->toBe((array) $before);
})->with(['binding', 'proof_content']);

it('admits reordered proof objects without changing their meaning', function () {
    $run = mlbBoundProofRun($this->proofRoot);
    $metadata = $run->metadata;
    $metadata['mlb_tabular_admission'] = array_reverse($metadata['mlb_tabular_admission'], true);
    DB::table('model_runs')->where('id', $run->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    $run->updateQuietly(['status' => 'failed']);
    expect($run->fresh()->status)->toBe('failed');
});

it('refuses oversized current metadata and nested proof before fetching the body', function (string $shape) {
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'training',
        'model_version' => 'legacy', 'feature_version' => 'legacy', 'blend_version' => 'legacy', 'config_hash' => hash('sha256', 'legacy'),
        'status' => 'running', 'started_at' => now(), 'metadata' => []]);
    if ($shape === 'record') {
        DB::statement('UPDATE model_runs SET metadata = CAST(zeroblob(?) AS TEXT) WHERE id = ?', [67108865, $run->id]);
    } else {
        DB::statement("UPDATE model_runs SET metadata = json_object('mlb_tabular_admission', json_object('padding', replace(hex(zeroblob(?)), '00', 'x'))) WHERE id = ?", [2097153, $run->id]);
    }
    $minimal = ModelRun::query()->select(['id', 'status'])->findOrFail($run->id);
    expect(fn () => $minimal->updateQuietly(['status' => 'failed']))->toThrow(LogicException::class);
    expect(DB::table('model_runs')->where('id', $run->id)->value('status'))->toBe('running');
})->with(['record', 'proof']);

it('roundtrips the explicit reported generation instant through the actual typed registrar', function () {
    $run = mlbBoundProofRun($this->proofRoot);
    $reported = Carbon::parse($run->metadata['python_manifest']['generated_at'])->getTimestamp();
    expect($run->started_at->getTimestamp())->toBe($reported);
});

it('refuses unknown or malformed reported generation times before SQL or owned object publication', function (string $clock) {
    $source = ObservedMlbDatasetFixture::create(true);
    try {
        $fixture = SyntheticMlbTabularBundleFixture::create($this->proofRoot.'/reported-clock', $source['path']);
        $manifest = json_decode(file_get_contents($fixture['run_directory'].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($clock === 'missing') {
            unset($manifest['generated_at']);
        } else {
            $manifest['generated_at'] = $clock === 'calendar' ? '2026-02-30T06:00:00Z' : '2026-06-30 06:00:00';
        }
        file_put_contents($fixture['run_directory'].'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        expect(fn () => app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']))->toThrow(RuntimeException::class);
        expect(ModelArtifact::count())->toBe(0)
            ->and(ModelRun::where('run_type', 'training')->count())->toBe(0);
    } finally {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
})->with(['missing', 'calendar', 'naive']);
