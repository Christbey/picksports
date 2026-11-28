<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;

it('preserves released evidence when a market attempts to move to an editable owner', function (string $state, bool $nativeTarget) {
    $owner = CanonicalPrediction::factory()->create(['calculation_run_id' => CalculationRun::factory(), 'publication_state' => 'draft']);
    $market = PredictionMarket::factory()->create(['prediction_id' => $owner->id]);
    // Reach each released state through the model's supported lifecycle.
    $owner->update(['publication_state' => 'published', 'published_at' => now()]);
    if ($state !== 'published') {
        $owner->update(['publication_state' => $state, $state.'_at' => now()]);
    }
    $before = $market->fresh()->getRawOriginal();
    $target = CanonicalPrediction::factory()->create(['publication_state' => 'draft',
        'calculation_run_id' => $nativeTarget ? CalculationRun::factory() : null]);

    expect(fn () => $market->update(['prediction_id' => $target->id, 'probability' => 0.1]))
        ->toThrow(LogicException::class);
    expect($market->fresh()->getRawOriginal())->toBe($before);
    expect($market->fresh()->prediction_id)->toBe($owner->id)
        ->and($owner->markets()->count())->toBe(1)
        ->and($target->markets()->count())->toBe(0);
})->with(['published', 'superseded', 'withdrawn'])->with([true, false]);

it('preserves editable draft and legacy market operations and native deletion protection', function () {
    $draft = CanonicalPrediction::factory()->create(['calculation_run_id' => CalculationRun::factory(), 'publication_state' => 'draft']);
    $legacy = CanonicalPrediction::factory()->create(['calculation_run_id' => null]);
    $market = PredictionMarket::factory()->create(['prediction_id' => $draft->id]);
    $market->update(['probability' => 0.55]);
    expect($market->fresh()->probability)->toBe('0.550000');
    expect(fn () => $market->delete())->toThrow(LogicException::class, 'cannot be deleted');
    $market->update(['prediction_id' => $legacy->id, 'probability' => 0.6]);
    expect($market->fresh()->prediction_id)->toBe($legacy->id);
    $market->delete();
    expect(PredictionMarket::find($market->id))->toBeNull();
});

it('does not allow an unsaved owner change to bypass native market deletion protection', function () {
    $owner = CanonicalPrediction::factory()->create(['calculation_run_id' => CalculationRun::factory(), 'publication_state' => 'draft']);
    $market = PredictionMarket::factory()->create(['prediction_id' => $owner->id]);
    $legacy = CanonicalPrediction::factory()->create(['calculation_run_id' => null]);
    $market->prediction_id = $legacy->id;
    expect(fn () => $market->delete())->toThrow(LogicException::class, 'cannot be deleted');
    expect($market->fresh()->prediction_id)->toBe($owner->id);
});
