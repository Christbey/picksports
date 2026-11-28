<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ShadowArtifactSelector;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-11-01 10:00:00');
    Http::preventStrayRequests();
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function existingMutationArtifact(string $sport = 'mlb', bool $active = false): ModelArtifact
{
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => $sport, 'run_type' => 'training',
        'model_version' => $sport.'-mutation-v1', 'feature_version' => 'synthetic-v1', 'blend_version' => 'synthetic-v1',
        'config_hash' => hash('sha256', 'synthetic'), 'status' => 'completed', 'started_at' => now()->subDays(2), 'completed_at' => now()->subDay(), 'metadata' => []]);

    $id = (string) Str::uuid();

    return ModelArtifact::create(['id' => $id, 'training_run_id' => $run->id, 'sport' => $sport,
        'market_type' => 'multi_market', 'model_type' => $sport.'_tabular_bundle', 'model_version' => $run->model_version,
        'feature_version' => $run->feature_version, 'dataset_hash' => hash('sha256', 'synthetic-dataset'),
        'artifact_path' => storage_path('framework/testing/never-opened-artifact.zip'), 'artifact_hash' => hash('sha256', 'synthetic-artifact-'.$id),
        'status' => 'challenger', 'metrics' => ['shadow_selection' => ['active' => $active]]])->refresh();
}

function existingSelectionRows(): array
{
    return [
        'artifacts' => DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'runs' => DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function existingSelectionAttempt(callable $write): ?Throwable
{
    try {
        $write();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

it('refuses unsupported MLB activation before changing any existing cohort or run row', function () {
    $artifact = existingMutationArtifact();
    $before = existingSelectionRows();
    $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->activateChallenger($artifact));
    expect(existingSelectionRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses unsupported MLB deactivation before changing any existing cohort or run row', function () {
    $artifact = existingMutationArtifact(active: true);
    $before = existingSelectionRows();
    $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->deactivateChallenger($artifact, 'synthetic-boundary'));
    expect(existingSelectionRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('does not derive the MLB cohort predicate from a modified caller sport or model family', function (string $field, string $value) {
    $current = existingMutationArtifact(active: true);
    $candidate = existingMutationArtifact();
    $before = existingSelectionRows();
    $candidate->setAttribute($field, $value);
    $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->activateChallenger($candidate));
    expect(existingSelectionRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with(['sport' => ['sport', 'nfl'], 'family' => ['model_type', 'different_family']]);

it('retains existing NFL selector activation and deactivation behavior', function () {
    $artifact = existingMutationArtifact('nfl');
    $selector = app(ShadowArtifactSelector::class);
    $selector->activateChallenger($artifact);
    expect(data_get($artifact->metrics, 'shadow_selection.active'))->toBeTrue();
    $selector->deactivateChallenger($artifact, 'synthetic-complete');
    expect(data_get($artifact->metrics, 'shadow_selection.active'))->toBeFalse()
        ->and(data_get($artifact->metrics, 'shadow_selection.deactivation_reason'))->toBe('synthetic-complete');
});

it('refuses a stale generic target before deactivating its existing peer', function (string $field, string $value) {
    $peer = existingMutationArtifact('nfl', true);
    $target = existingMutationArtifact('nfl');
    DB::table('model_artifacts')->where('id', $target->id)->update([$field => $value]);
    $before = existingSelectionRows();
    $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->activateChallenger($target));
    expect(existingSelectionRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with(['retired' => ['status', 'retired']]);

it('rolls back peer deactivation when selected persistence is cancelled', function () {
    $peer = existingMutationArtifact('nfl', true);
    $target = existingMutationArtifact('nfl');
    $before = existingSelectionRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function (ModelArtifact $row) use ($target, &$attempts) {
        if ($row->id === $target->id) {
            $attempts++;

            return false;
        }
    });
    try {
        $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->activateChallenger($target));
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($attempts)->toBe(1)->and(existingSelectionRows())->toBe($before)
        ->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('does not route malformed persisted MLB spelling through the generic selector', function () {
    $target = existingMutationArtifact();
    DB::table('model_artifacts')->where('id', $target->id)->update(['sport' => ' mlb ']);
    $target->refresh();
    $before = existingSelectionRows();
    $failure = existingSelectionAttempt(fn () => app(ShadowArtifactSelector::class)->activateChallenger($target));
    expect(existingSelectionRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
});
