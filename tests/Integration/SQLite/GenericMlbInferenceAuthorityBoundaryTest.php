<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-06-27T06:00:00Z');
    CarbonImmutable::setTestNow('2026-06-27T06:00:00Z');
    Storage::fake('generic-authority-artifacts');
    Storage::fake('generic-authority-cache');
    config(['ml.storage.disk' => 'generic-authority-artifacts', 'ml.storage.cache_disk' => 'generic-authority-cache']);
    $this->authorityRoot = storage_path('framework/testing/generic-authority-'.Str::uuid());
    config(['mlb_ml.bundle.staging_directory' => $this->authorityRoot.'/stage']);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    File::deleteDirectory($this->authorityRoot);
});

function genericAuthorityArtifact($test): ModelArtifact
{
    $source = ObservedMlbDatasetFixture::create(retainDataset: true, gameDate: '2026-06-28',
        finalizedAt: Carbon::parse('2026-06-30T06:00:00Z'));
    Carbon::setTestNow('2026-06-30T06:00:00Z');
    CarbonImmutable::setTestNow('2026-06-30T06:00:00Z');
    try {
        $fixture = SyntheticMlbTabularBundleFixture::create($test->authorityRoot.'/run', $source['path']);
        $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
        expect(app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact']->id)->toBe($artifact->id);

        return $artifact;
    } finally {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
}

function genericAuthorityCapture(array $metadata = []): PredictionFeatureSnapshot
{
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-07-03', 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5,
        'predicted_total' => 8.5, 'win_probability' => 0.57, 'confidence_score' => 57]);

    return app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => 0.57],
        'market_context' => [], 'model_metadata' => $metadata,
    ]);
}

function genericAuthorityContext(ModelArtifact $artifact): array
{
    return ['artifact_id' => $artifact->id, 'artifact_hash' => $artifact->artifact_hash,
        'training_run_id' => $artifact->training_run_id, 'dataset_hash' => $artifact->dataset_hash,
        'baseline_output' => 0.57, 'challenger_output' => 0.61, 'market_type' => 'win_probability'];
}

it('retains a real observed MLB capture without treating serialized context as completed model execution', function () {
    $artifact = genericAuthorityArtifact($this);
    $runsBefore = DB::table('model_runs')->where('run_type', 'shadow_inference')->orderBy('id')->get()->all();
    $outputsBefore = DB::table('shadow_model_outputs')->orderBy('id')->get()->all();
    $snapshot = genericAuthorityCapture(['shadow_inference' => genericAuthorityContext($artifact)]);
    expect($snapshot->exists)->toBeTrue()
        ->and(data_get($snapshot->model_metadata, 'mlb_capture_integrity.qualified'))->toBeTrue()
        ->and(DB::table('shadow_model_outputs')->orderBy('id')->get()->all())->toEqual($outputsBefore)
        ->and(DB::table('model_runs')->where('run_type', 'shadow_inference')->orderBy('id')->get()->all())->toEqual($runsBefore);
});

it('does not create MLB execution outputs from a foreign sport artifact context', function () {
    $artifact = genericAuthorityArtifact($this);
    // Deliberately unqualified current classification; never claimed valid NFL training provenance.
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['sport' => 'nfl']);
    $artifact->refresh();
    $snapshot = genericAuthorityCapture(['shadow_inference' => genericAuthorityContext($artifact)]);
    expect(data_get($snapshot->model_metadata, 'mlb_capture_integrity.qualified'))->toBeTrue()
        ->and(ShadowModelOutput::count())->toBe(0)
        ->and(ModelRun::where('run_type', 'shadow_inference')->count())->toBe(0);
});

it('does not create MLB execution outputs from a wrong current artifact family', function () {
    $artifact = genericAuthorityArtifact($this);
    // Current family mutation is an explicitly unsupported negative, not a period model proof.
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['model_type' => 'mlb_period_bundle']);
    $artifact->refresh();
    $snapshot = genericAuthorityCapture(['shadow_inference' => genericAuthorityContext($artifact)]);
    expect(data_get($snapshot->model_metadata, 'mlb_capture_integrity.qualified'))->toBeTrue()
        ->and(ShadowModelOutput::count())->toBe(0)
        ->and(ModelRun::where('run_type', 'shadow_inference')->count())->toBe(0);
});

it('uses current persisted MLB classification when the caller edits in memory sport and context', function () {
    $artifact = genericAuthorityArtifact($this);
    $snapshot = genericAuthorityCapture()->fresh();
    $stored = $snapshot->getRawOriginal();
    $snapshot->sport = 'nfl';
    $metadata = $snapshot->model_metadata;
    $metadata['shadow_inference'] = genericAuthorityContext($artifact);
    $snapshot->model_metadata = $metadata;
    app(ShadowModelOutputRecorder::class)->record($snapshot);
    expect($snapshot->fresh()->getRawOriginal())->toBe($stored)
        ->and(ShadowModelOutput::count())->toBe(0)
        ->and(ModelRun::where('run_type', 'shadow_inference')->count())->toBe(0);
});

it('does not create completed execution authority after the current artifact training owner changes', function () {
    $artifact = genericAuthorityArtifact($this);
    $other = app(ModelRunRecorder::class)->create('mlb', 'explicit_unqualified_owner', 'synthetic', 'synthetic', 'none');
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $other->id]);
    $artifact->refresh();
    $snapshot = genericAuthorityCapture(['shadow_inference' => genericAuthorityContext($artifact)]);
    expect(data_get($snapshot->model_metadata, 'mlb_capture_integrity.qualified'))->toBeTrue()
        ->and(ModelRun::where('run_type', 'shadow_inference')->where('status', 'completed')->count())->toBe(0)
        ->and(ShadowModelOutput::count())->toBe(0);
});

it('returns preexisting historical MLB bookkeeping without authoring another completed run or changing rows', function () {
    $artifact = genericAuthorityArtifact($this);
    $snapshot = genericAuthorityCapture()->fresh();
    // Explicit retained-history fixture, not evidence of a new qualified execution.
    $run = app(ModelRunRecorder::class)->create('mlb', 'shadow_inference', 'historical-only', 'historical-only', 'none',
        metadata: ['fixture_scope' => 'preexisting_historical_bookkeeping'], status: 'completed', completedAt: now());
    $id = DB::table('shadow_model_outputs')->insertGetId(['inference_run_id' => $run->id,
        'model_artifact_id' => $artifact->id, 'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'mlb', 'game_table' => 'mlb_games', 'game_id' => $snapshot->game_id,
        'prediction_table' => $snapshot->prediction_table, 'prediction_id' => $snapshot->prediction_id,
        'market_type' => 'win_probability', 'baseline_output' => 0.57, 'challenger_output' => 0.61, 'output_delta' => 0.04,
        'status' => 'shadow', 'explanation' => json_encode(['fixture_scope' => 'preexisting_historical_bookkeeping']),
        'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $before = [DB::table('shadow_model_outputs')->orderBy('id')->get()->all(), DB::table('model_runs')->orderBy('id')->get()->all()];
    foreach ([0.61, 99.0, -10.0, 1e30] as $value) {
        $context = genericAuthorityContext($artifact);
        $context['challenger_output'] = $value;
        $metadata = $snapshot->model_metadata;
        $metadata['shadow_inference'] = $context;
        $snapshot->model_metadata = $metadata;
        expect(app(ShadowModelOutputRecorder::class)->record($snapshot)?->id)->toBe($id)
            ->and([DB::table('shadow_model_outputs')->orderBy('id')->get()->all(), DB::table('model_runs')->orderBy('id')->get()->all()])->toEqual($before);
    }
    foreach ([
        [['market_type' => 'total'], null],
        [['market_type' => 'unsupported'], null],
        [['market_type' => []], null],
        [['baseline_outputs' => ['win_probability' => 99], 'challenger_outputs' => ['win_probability' => -10], 'market_promotion' => []], $id],
        [['baseline_outputs' => ['predicted_spread' => 99], 'challenger_outputs' => ['predicted_spread' => -10], 'market_promotion' => []], null],
    ] as [$selection, $expected]) {
        $metadata = $snapshot->model_metadata;
        $metadata['shadow_inference'] = [...genericAuthorityContext($artifact), ...$selection];
        $snapshot->model_metadata = $metadata;
        expect(app(ShadowModelOutputRecorder::class)->record($snapshot)?->id)->toBe($expected)
            ->and([DB::table('shadow_model_outputs')->orderBy('id')->get()->all(), DB::table('model_runs')->orderBy('id')->get()->all()])->toEqual($before);
    }
});
