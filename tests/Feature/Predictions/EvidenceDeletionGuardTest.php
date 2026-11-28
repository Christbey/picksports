<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;

it('protects a persisted native prediction when its run identity is changed only in memory', function () {
    $prediction = CanonicalPrediction::factory()->create(['calculation_run_id' => CalculationRun::factory()]);
    $before = $prediction->fresh()->getRawOriginal();
    $prediction->calculation_run_id = null;
    expect(fn () => $prediction->delete())->toThrow(LogicException::class);
    expect($prediction->fresh()?->getRawOriginal())->toBe($before);
});

it('protects canonical evaluation history when its owner is cleared only in memory', function () {
    $prediction = CanonicalPrediction::factory()->create();
    $evaluation = PredictionEvaluation::query()->create([
        'canonical_prediction_id' => $prediction->id, 'sport' => $prediction->sport,
        'actuals' => [], 'errors' => [], 'evaluated_at' => now(),
    ]);
    $evaluation->canonical_prediction_id = null;
    expect(fn () => $evaluation->delete())->toThrow(LogicException::class, 'immutable');
    expect($evaluation->fresh()->canonical_prediction_id)->toBe($prediction->id);
});

it('protects an unreferenced released definition when its status is changed only in memory', function (string $status) {
    $release = CalculationRelease::factory()->create(['status' => $status]);
    $before = $release->fresh()->getRawOriginal();
    $release->status = 'draft';
    expect(fn () => $release->delete())->toThrow(LogicException::class);
    expect($release->fresh()?->getRawOriginal())->toBe($before);
    expect($release->fresh()->status)->toBe($status);
})->with(['approved', 'retired', 'invalidated']);

it('preserves deliberate deletion of draft definitions and legacy predictions and evaluations', function () {
    $release = CalculationRelease::factory()->create();
    $release->delete();
    $prediction = CanonicalPrediction::factory()->create(['calculation_run_id' => null]);
    $prediction->delete();
    $evaluation = PredictionEvaluation::query()->create([
        'sport' => 'nba', 'actuals' => [], 'errors' => [], 'evaluated_at' => now(),
    ]);
    $evaluation->delete();
    expect(CalculationRelease::find($release->id))->toBeNull()
        ->and(CanonicalPrediction::find($prediction->id))->toBeNull()
        ->and(PredictionEvaluation::find($evaluation->id))->toBeNull();
});
