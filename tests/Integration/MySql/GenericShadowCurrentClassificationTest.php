<?php

use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    $this->roleDefault = DB::getDefaultConnection();
    config(['database.connections.role_peer' => config('database.connections.'.$this->roleDefault)]);
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('role_peer')->getPdo());
    Storage::fake('role-source');
    Storage::fake('role-object');
    Storage::fake('role-cache');
    config(['ml.storage.disk' => 'role-object', 'ml.storage.cache_disk' => 'role-cache']);
});

afterEach(function () {
    DB::setDefaultConnection($this->roleDefault);
    foreach ([$this->roleDefault, 'role_peer'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }
    DB::purge('role_peer');
});

function currentRoleNativeFixture(): array
{
    $run = app(ModelRunRecorder::class)->create('nfl', 'training', 'synthetic-role-v1', 'synthetic-role-v1', 'none', status: 'completed', completedAt: now());
    Storage::disk('role-source')->put('p.json', '{"alpha":1,"beta":0}');
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), $run, 'win_probability',
        'nfl_full_historical_profile', 'synthetic-role-v1', 'synthetic-role-v1', hash('sha256', 'opaque-role-fixture'), Storage::disk('role-source')->path('p.json'), []);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'game_date' => '2026-12-01', 'game_time' => '19:00:00', 'status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.0, 'predicted_total' => 44.0,
        'win_probability' => 0.55, 'confidence_score' => 55]);
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions',
        'prediction_id' => $prediction->id, 'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $run->id,
        'model_version' => 'synthetic-role-v1', 'feature_version' => 'synthetic-role-v1', 'blend_version' => 'baseline-v1',
        'features' => ['signal' => 1], 'outputs' => ['win_probability' => 0.55], 'market_context' => [],
        'model_metadata' => ['shadow_inference' => ['artifact_id' => $artifact->id, 'baseline_output' => 0.55, 'challenger_output' => 0.61]],
        'feature_hash' => hash('sha256', 'synthetic-role'), 'generated_at' => now(), 'game_start_at' => now()->addDays(4),
        'features_available_at' => now(), 'pregame_safe' => false, 'availability_status' => 'synthetic_role_control']);

    return [$artifact, $snapshot->fresh()];
}

it('does not authorize generic output from an inherited RR view after an independent current MLB classification change', function () {
    [$artifact, $snapshot] = currentRoleNativeFixture();
    $reader = DB::connection();
    $peer = DB::connection('role_peer');
    $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $reader->beginTransaction();
    try {
        expect($reader->table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('sport'))->toBe('nfl');
        // Explicit invalid current classification, not a qualified MLB capture or producer.
        $peer->table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['sport' => 'mlb']);
        expect($peer->table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('sport'))->toBe('mlb')
            ->and($reader->table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('sport'))->toBe('nfl');
        $result = app(ShadowModelOutputRecorder::class)->record($snapshot);
        $createdRunIds = $reader->table('model_runs')->where('run_type', 'shadow_inference')->pluck('id')->all();
        $reader->commit();
        $committedOutputs = $peer->table('shadow_model_outputs')->where('prediction_feature_snapshot_id', $snapshot->id)->count();
        $committedRuns = $peer->table('model_runs')->where('run_type', 'shadow_inference')->where('status', 'completed')->count();
        fwrite(STDERR, 'native_classification_observation='.json_encode(['current_sport' => $peer->table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('sport'),
            'committed_outputs' => $committedOutputs, 'completed_runs' => $committedRuns], JSON_THROW_ON_ERROR)."\n");
        expect($committedOutputs)->toBe(0)->and($committedRuns)->toBe(0)->and($result)->toBeNull();
    } finally {
        while ($reader->transactionLevel() > 0) {
            $reader->rollBack();
        }
        $peer->table('shadow_model_outputs')->where('prediction_feature_snapshot_id', $snapshot->id)->delete();
        if (isset($createdRunIds) && $createdRunIds !== []) {
            $peer->table('model_runs')->whereIn('id', $createdRunIds)->delete();
        }
        $peer->table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['sport' => 'nfl']);
    }
});

it('retains ordinary native NFL generic private bookkeeping without changing artifact or training rows', function () {
    [$artifact, $snapshot] = currentRoleNativeFixture();
    $artifactBefore = $artifact->fresh()->getRawOriginal();
    $runBefore = $artifact->trainingRun->getRawOriginal();
    $output = app(ShadowModelOutputRecorder::class)->record($snapshot);
    expect($output)->toBeInstanceOf(ShadowModelOutput::class)
        ->and($output->sport)->toBe('nfl')->and($output->model_artifact_id)->toBe($artifact->id)
        ->and($output->baseline_output)->toBe(0.55)->and($output->challenger_output)->toBe(0.61)
        ->and($artifact->fresh()->getRawOriginal())->toBe($artifactBefore)
        ->and($artifact->trainingRun->fresh()->getRawOriginal())->toBe($runBefore);
});

it('locks every planned native artifact leaf in UUID order while preserving context return order and allowing retry', function () {
    [$original, $snapshot] = currentRoleNativeFixture();
    $ids = ['10000000-0000-4000-8000-000000000001', 'f0000000-0000-4000-8000-000000000002'];
    foreach ($ids as $id) {
        $name = 'leaf-'.$id[0].'.json';
        Storage::disk('role-source')->put($name, json_encode(['alpha' => 1, 'beta' => 0, 'synthetic_fixture_id' => $id], JSON_THROW_ON_ERROR));
        app(ModelArtifactRegistry::class)->register($id, $original->trainingRun, 'win_probability',
            'nfl_full_historical_profile', 'synthetic-role-v1', 'synthetic-role-v1', hash('sha256', 'opaque-role-fixture'), Storage::disk('role-source')->path($name), []);
    }
    $snapshot->model_metadata = ['shadow_inference' => ['cohort' => array_map(fn ($id) => ['artifact_id' => $id, 'baseline_output' => 0.55, 'challenger_output' => 0.61], array_reverse($ids))]];
    $connection = DB::connection();
    $peer = DB::connection('role_peer');
    $peer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $before = $peer->table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $originalQueryEvents = $connection->getEventDispatcher();
    $queryEvents = clone $originalQueryEvents;
    $seen = [];
    $queryEvents->listen(QueryExecuted::class, function ($query) use (&$seen, $ids) {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'select') && str_contains($sql, 'model_artifacts') && str_contains($sql, 'for update')) {
            foreach ($query->bindings as $binding) {
                if (in_array($binding, $ids, true)) {
                    $seen[] = $binding;
                }
            }
        }
    });
    $connection->setEventDispatcher($queryEvents);
    $originalModelEvents = ModelRun::getEventDispatcher();
    ModelRun::setEventDispatcher(clone $originalModelEvents);
    $blocked = [];
    ModelRun::creating(function (ModelRun $run) use ($peer, $ids, &$blocked) {
        if ($run->run_type !== 'shadow_inference') {
            return;
        }
        foreach ($ids as $id) {
            $peer->beginTransaction();
            try {
                $peer->table('model_artifacts')->where('id', $id)->update(['metrics' => DB::raw('metrics')]);
                throw new LogicException('Planned native artifact leaf was not held before inference-run append.');
            } catch (QueryException $error) {
                expect((int) $error->errorInfo[1])->toBe(1205)
                    ->and(strtolower($error->getSql()))->toContain('update `model_artifacts`');
                $blocked[] = $id;
            } finally {
                while ($peer->transactionLevel() > 0) {
                    $peer->rollBack();
                }
            }
        }
    });
    try {
        $first = app(ShadowModelOutputRecorder::class)->record($snapshot);
    } finally {
        $connection->setEventDispatcher($originalQueryEvents);
        ModelRun::setEventDispatcher($originalModelEvents);
    }
    expect(array_values(array_unique($seen)))->toBe($ids)
        ->and(array_values(array_unique($blocked)))->toBe($ids)
        ->and($first?->model_artifact_id)->toBe($ids[1])
        ->and(ShadowModelOutput::whereIn('model_artifact_id', $ids)->count())->toBe(2);
    foreach ($ids as $id) {
        $peer->table('model_artifacts')->where('id', $id)->update(['metrics' => DB::raw('metrics')]);
    }
    expect($peer->table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});
