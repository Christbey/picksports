<?php

use App\Models\DeveloperApiUsageRecord;

it('keeps metered usage immutable through ordinary quiet and numeric model methods', function (string $method) {
    $model = DeveloperApiUsageRecord::factory()->create(['units' => 3]);
    $model->refresh();
    $before = $model->getRawOriginal();
    $mutation = function () use ($model, $method): void {
        if (in_array($method, ['save', 'saveQuietly'], true)) {
            $model->units = 4;
            $model->{$method}();
        } elseif (in_array($method, ['delete', 'deleteQuietly'], true)) {
            $model->{$method}();
        } else {
            $model->{$method}('units');
        }
    };
    expect($mutation)->toThrow(LogicException::class, 'Developer API usage records are immutable.');
    expect($model->newQuery()->findOrFail($model->getKey())->getRawOriginal())->toBe($before);
})->with(['save', 'delete', 'saveQuietly', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly']);
