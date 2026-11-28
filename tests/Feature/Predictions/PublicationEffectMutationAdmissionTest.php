<?php

use App\Models\CanonicalPrediction;
use App\Models\PredictionPublicationEffect;

function publicationMutationFixture(string $state = 'pending'): PredictionPublicationEffect
{
    return PredictionPublicationEffect::create(['canonical_prediction_id' => CanonicalPrediction::factory()->create()->id,
        'effect' => 'cache', 'payload' => ['sport' => 'nba', 'game_id' => 10], 'state' => $state, 'attempts' => 3])->refresh();
}

test('publication effects refuse quiet identity changes and deletion', function (string $kind) {
    $effect = publicationMutationFixture();
    $before = $effect->getRawOriginal();
    $mutation = function () use ($effect, $kind): void {
        if ($kind === 'delete') {
            $effect->deleteQuietly();
        } elseif ($kind === 'numeric_extra') {
            $effect->incrementQuietly('attempts', 1, ['payload' => '{"sport":"changed"}']);
        } else {
            $effect->{$kind} = match ($kind) {
                'effect' => 'narrative', 'canonical_prediction_id' => CanonicalPrediction::factory()->create()->id,
                default => ['sport' => 'changed'],
            };
            $effect->saveQuietly();
        }
    };
    expect($mutation)->toThrow(LogicException::class);
    expect($effect->newQuery()->findOrFail($before['id'])->getRawOriginal())->toBe($before);
})->with(['effect', 'canonical_prediction_id', 'payload', 'delete', 'numeric_extra']);

test('terminal publication receipts refuse quiet mutation methods', function (string $state, string $method) {
    $effect = publicationMutationFixture($state);
    $before = $effect->getRawOriginal();
    $mutation = function () use ($effect, $method): void {
        if ($method === 'saveQuietly') {
            $effect->attempts = 4;
            $effect->saveQuietly();
        } elseif ($method === 'deleteQuietly') {
            $effect->deleteQuietly();
        } else {
            $effect->{$method}('attempts');
        }
    };
    expect($mutation)->toThrow(LogicException::class);
    expect($effect->newQuery()->findOrFail($before['id'])->getRawOriginal())->toBe($before);
})->with(function () {
    foreach (['completed', 'cancelled', 'exhausted'] as $state) {
        foreach (['saveQuietly', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly'] as $method) {
            yield $state.' '.$method => [$state, $method];
        }
    }
});

test('publication receipts use current terminal state rather than stale instance state', function (string $method) {
    $effect = publicationMutationFixture();
    $effect->newQuery()->whereKey($effect->id)->update(['state' => 'completed']);
    $before = $effect->fresh()->getRawOriginal();
    $mutation = function () use ($effect, $method): void {
        if ($method === 'numeric') {
            $effect->incrementQuietly('attempts');
        } else {
            $effect->attempts = 4;
            $effect->{$method}();
        }
    };
    expect($mutation)->toThrow(LogicException::class);
    expect($effect->fresh()->getRawOriginal())->toBe($before);
})->with(['save', 'saveQuietly', 'numeric']);

test('pending publication receipts retain legitimate mutable field operations', function () {
    $effect = publicationMutationFixture();
    $effect->update(['state' => 'queued']);
    $effect->forceFill(['reason_code' => 'fixture'])->saveQuietly();
    $effect->increment('attempts');
    $effect->incrementQuietly('attempts');
    expect($effect->fresh()->state)->toBe('queued')
        ->and($effect->fresh()->reason_code)->toBe('fixture')
        ->and($effect->fresh()->attempts)->toBe(5);
});

test('publication effect mutable writes refuse current identity drift', function (string $field) {
    $effect = publicationMutationFixture();
    $changed = match ($field) {
        'canonical_prediction_id' => CanonicalPrediction::factory()->create()->id,
        'effect' => 'narrative',
        default => '{"sport":"changed"}',
    };
    $effect->newQuery()->whereKey($effect->id)->update([$field => $changed]);
    $before = $effect->fresh()->getRawOriginal();
    $effect->reason_code = 'stale-worker';
    expect(fn () => $effect->saveQuietly())->toThrow(LogicException::class);
    expect($effect->fresh()->getRawOriginal())->toBe($before);
})->with(['canonical_prediction_id', 'effect', 'payload']);

test('publication effect identity comparison preserves SQL JSON object order equivalence', function () {
    $effect = publicationMutationFixture();
    $effect->newQuery()->whereKey($effect->id)->update(['payload' => '{"game_id":10,"sport":"nba"}']);
    $effect->reason_code = 'legitimate-update';
    $effect->saveQuietly();
    expect($effect->fresh()->reason_code)->toBe('legitimate-update');
});
