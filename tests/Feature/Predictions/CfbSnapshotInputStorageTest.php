<?php

use App\Models\EventInputSnapshot;
use App\Models\SnapshotMetadataPayload;
use App\Services\Predictions\CfbSnapshotInputStorage;
use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Support\Facades\DB;

it('shares large CFB input sections and rehydrates legacy, compact, and projected reads exactly', function () {
    $section = ['number' => 1.0, 'string' => str_repeat('sample ', 700), 'unavailable' => null];
    $inputs = ['historical_signals' => $section, 'football_signal_evidence' => $section,
        'scoring_challenger' => ['small' => true], 'signal_context' => ['conference' => true]];
    $storage = app(CfbSnapshotInputStorage::class);
    $inline = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'inputs' => $inputs])->fresh();
    $compacted = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'inputs' => $storage->prepare($inputs)])->fresh();
    $raw = json_decode($compacted->getRawOriginal('inputs'), true, 512, JSON_THROW_ON_ERROR);
    expect($raw['historical_signals'])->toStartWith(SnapshotMetadataStorage::REFERENCE_PREFIX)
        ->and($raw['historical_signals'])->toBe($raw['football_signal_evidence'])
        ->and(SnapshotMetadataPayload::count())->toBe(1)
        ->and($compacted->inputs)->toBe($inline->inputs);
    $projection = EventInputSnapshot::query()->select(['inputs->historical_signals as evidence'])->findOrFail($compacted->id);
    expect($storage->resolveProjectedSection($projection->evidence))->toBe($inline->inputs['historical_signals']);

    DB::enableQueryLog();
    expect($compacted->inlineInputs())->toBe($raw)
        ->and(DB::getQueryLog())->toBeEmpty();
    $compacted->withCachedJsonArrays(function () use ($compacted, $inline): void {
        expect($compacted->inputs)->toBe($inline->inputs)
            ->and($compacted->inputs)->toBe($inline->inputs);
    });
    $lookups = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'snapshot_metadata_payloads'));
    DB::disableQueryLog();
    expect($lookups)->toHaveCount(1);

    SnapshotMetadataPayload::query()->delete();
    expect(fn () => $compacted->fresh()->inputs)->toThrow(RuntimeException::class, 'Referenced CFB snapshot inputs are missing.');
});
