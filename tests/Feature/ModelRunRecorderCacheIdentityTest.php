<?php

use App\Models\ModelRun;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;

it('does not reuse a run whose enclosing transaction rolled back', function () {
    $recorder = new ModelRunRecorder;
    try {
        DB::transaction(function () use ($recorder) {
            $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1');
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('synthetic rollback');
    }
    expect(ModelRun::query()->count())->toBe(0);
    $retry = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1');
    expect(ModelRun::query()->whereKey($retry->getKey())->exists())->toBeTrue();
});

it('keeps different artifact parameters in distinct run identities', function () {
    $recorder = new ModelRunRecorder;
    $first = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['model_artifact_id' => 'synthetic-artifact-A']]);
    $second = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['model_artifact_id' => 'synthetic-artifact-B']]);
    expect($second->getKey())->not->toBe($first->getKey());
    expect($second->fresh()->parameters)->toBe(['model_artifact_id' => 'synthetic-artifact-B']);
});

it('does not collide version fields containing the cache separator', function () {
    $recorder = new ModelRunRecorder;
    $first = $recorder->forPrediction('nfl', 'a|b', 'c', 'd');
    $second = $recorder->forPrediction('nfl', 'a', 'b|c', 'd');
    expect($second->getKey())->not->toBe($first->getKey());
    expect($second->fresh()->model_version)->toBe('a');
});

it('retains reuse for the same run parameters regardless of dictionary key order', function () {
    $recorder = new ModelRunRecorder;
    $first = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['a' => 'one', 'b' => 'two']]);
    $second = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['b' => 'two', 'a' => 'one']]);
    expect($second->getKey())->toBe($first->getKey());
    expect(ModelRun::query()->count())->toBe(1);
});

it('distinguishes a numeric key map from a JSON list in nested run parameters', function () {
    $recorder = new ModelRunRecorder;
    $map = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['weights' => [1 => 'b', 0 => 'a']]]);
    $list = $recorder->forPrediction('nfl', 'synthetic-cache-model', 'features-v1', 'blend-v1', ['parameters' => ['weights' => [0 => 'a', 1 => 'b']]]);
    expect($list->getKey())->not->toBe($map->getKey());
    expect($list->fresh()->getRawOriginal('parameters'))->toBe('{"weights":["a","b"]}');
});
