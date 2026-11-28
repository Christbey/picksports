<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Services\Predictions\CanonicalPredictionEvaluator;
use App\Services\Predictions\SportEventResultRecorder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.result_writer' => config('database.connections.mysql')]);
    $this->event = SportEvent::factory()->create(['sport' => 'nba']);
    $this->older = app(SportEventResultRecorder::class)->record($this->event, 108, 100, 'espn');
});

afterEach(function () {
    DB::setDefaultConnection('mysql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('result_writer');
});

function commitCorrectionOutsideResultSnapshot(SportEvent $event): SportEventResult
{
    DB::beginTransaction();
    // Fix the first connection's consistent read before the second commits.
    SportEventResult::query()->count();
    DB::setDefaultConnection('result_writer');
    try {
        return app(SportEventResultRecorder::class)->record($event, 99, 100, 'espn');
    } finally {
        DB::setDefaultConnection('mysql');
    }
}

it('uses a current read when allocating a result revision after an older snapshot', function () {
    $corrected = commitCorrectionOutsideResultSnapshot($this->event);
    $replayed = app(SportEventResultRecorder::class)->record($this->event, 99, 100, 'espn');
    expect($replayed->id)->toBe($corrected->id);
    DB::commit();
    expect(SportEventResult::query()->count())->toBe(2);
});

it('does not grade an old result hidden behind a repeatable read snapshot', function () {
    $prediction = CanonicalPrediction::factory()->create([
        'sport_event_id' => $this->event->id, 'sport' => 'nba', 'phase' => 'pregame',
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
        'publication_state' => 'draft',
        'output_metadata' => ['market_conventions' => ['spread' => 'sportsbook_home_line']],
    ]);
    foreach ([['moneyline', 'home', 0.65, null], ['spread', 'home', null, -6.5], ['total', 'combined', null, 210.5]] as [$type, $selection, $probability, $line]) {
        PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => $type,
            'selection' => $selection, 'probability' => $probability, 'projected_line' => $line]);
    }
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);
    $corrected = commitCorrectionOutsideResultSnapshot($this->event);
    $evaluator = app(CanonicalPredictionEvaluator::class);
    expect(fn () => $evaluator->evaluate($prediction, $this->older))
        ->toThrow(PredictionLifecycleException::class, 'superseded result');
    $evaluation = $evaluator->evaluate($prediction, $corrected);
    DB::commit();
    expect($evaluation->sport_event_result_id)->toBe($corrected->id)
        ->and($evaluation->actuals['home_score'])->toBe(99)
        ->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and($prediction->fresh()->latestEvaluation->id)->toBe($evaluation->id)
        ->and(CanonicalPrediction::query()->whereKey($prediction->id)->whereHas('latestEvaluation')->exists())->toBeTrue();
});
