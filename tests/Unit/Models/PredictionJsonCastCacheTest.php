<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use Illuminate\Database\Eloquent\Casts\Json;

it('decodes prediction JSON once while preserving array and mutation semantics', function (string $class, string $field) {
    $decodes = 0;
    Json::decodeUsing(function ($value, $associative) use (&$decodes) {
        $decodes++;

        return json_decode($value, $associative);
    });

    try {
        $model = new $class;
        $model->setRawAttributes([$field => '{"nested":{"score":1},"list":[null,2.5]}'], true);
        $model->withCachedJsonArrays(function () use ($model, $field, &$decodes) {
            $expected = ['nested' => ['score' => 1], 'list' => [null, 2.5]];
            expect($model->{$field})->toBe($expected)
                ->and($model->{$field})->toBe($expected)
                ->and($decodes)->toBe(1)
                ->and($model->isDirty())->toBeFalse();

            $copy = $model->{$field};
            $copy['nested']['score'] = 99;
            expect($model->{$field})->toBe($expected);

            $model->{$field} = ['nested' => ['score' => 2]];
            expect($model->{$field})->toBe(['nested' => ['score' => 2]])
                ->and($model->isDirty($field))->toBeTrue()
                ->and($model->toArray()[$field])->toBe(['nested' => ['score' => 2]]);

            $model->setAttribute($field.'->nested->score', 3);
            expect($model->{$field})->toBe(['nested' => ['score' => 3]]);
            $model->setRawAttributes([$field => '{"refreshed":true}'], true);
            expect($model->{$field})->toBe(['refreshed' => true]);
            $model->{$field} = null;
            expect($model->{$field})->toBeNull();
            $model->mergeCasts([$field => 'object']);
            $model->setRawAttributes([$field => '{"object":true}']);
            expect($model->{$field})->toBeInstanceOf(stdClass::class);
        });
    } finally {
        Json::decodeUsing(null);
    }
})->with([
    [CanonicalPrediction::class, 'output_metadata'],
    [EventInputSnapshot::class, 'inputs'],
    [CalculationRun::class, 'diagnostics'],
]);

it('releases decoded documents after successful and failed read scopes', function () {
    $model = new EventInputSnapshot(['inputs' => ['score' => 1]]);
    $cache = new ReflectionProperty($model, 'decodedJsonArrays');
    $model->withCachedJsonArrays(function () use ($model, $cache) {
        expect($model->inputs)->toBe(['score' => 1]);
        $model->withCachedJsonArrays(fn () => expect($model->inputs)->toBe(['score' => 1]));
        expect($cache->getValue($model))->not->toBeEmpty();
    });
    expect($cache->getValue($model))->toBeEmpty();
    expect(fn () => $model->withCachedJsonArrays(function () use ($model) {
        $inputs = $model->inputs;
        throw new RuntimeException('render failed');
    }))->toThrow(RuntimeException::class, 'render failed');
    expect($cache->getValue($model))->toBeEmpty()
        ->and($model->inputs)->toBe(['score' => 1])
        ->and($cache->getValue($model))->toBeEmpty();
});
