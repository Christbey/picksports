<?php

use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\PredictionEvaluation;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true, 'prediction_lifecycle.canonical_reads.cfb' => false]);
    $this->mock(CfbCalculator::class)->shouldReceive('calculate')->andReturn(new PredictionOutput([
        new PredictionMarketOutput('moneyline', 'home', probability: 0.75),
        new PredictionMarketOutput('spread', 'home', projectedLine: -7),
        new PredictionMarketOutput('total', 'combined', projectedLine: 47),
    ]));
});

function modelComparisonRetirementFixture(string $variant = 'valid'): array
{
    $start = now()->subDays(2)->startOfHour();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $variant === 'missing_event' ? null : $event->id,
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'week' => 1, 'status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $legacy->forceFill(['updated_at' => $start->copy()->subHour()])->saveQuietly();
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'captured_at' => $variant === 'captured_at_kickoff' ? $start : $start->copy()->subHours(2), 'cutoff_at' => $start->copy()->subHours(2),
        'latest_source_available_at' => $variant === 'source_at_kickoff' ? $start : $start->copy()->subHours(3),
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id]);
    $forecast = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'revision' => 1,
        'publication_state' => $variant === 'withdrawn' ? 'withdrawn' : 'superseded',
        'published_at' => $variant === 'at_kickoff' ? $start : $start->copy()->subHour()]);
    if ($variant !== 'missing_evaluation') {
        PredictionEvaluation::create(['canonical_prediction_id' => $forecast->id, 'sport_event_id' => $event->id,
            'sport' => 'cfb', 'prediction_table' => 'predictions', 'prediction_id' => $forecast->id,
            'game_id' => $game->id, 'actuals' => ['home_margin' => 7, 'total_points' => 47],
            'errors' => ['winner_correct' => true, 'spread_absolute_error' => 1, 'total_absolute_error' => 2, 'brier_score' => 0.04],
            'evaluated_at' => now()]);
    }

    return [$game, $forecast];
}

function runRetiredModelComparison(array $options = []): array
{
    expect(Artisan::call('cfb:compare-prediction-models', ['--season' => 2026, ...$options]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('compares frozen canonical forecasts without reading legacy predictions under either switch', function (bool $pipeline, bool $reads) {
    config(['prediction_lifecycle.canonical_pipeline.cfb' => $pipeline, 'prediction_lifecycle.canonical_reads.cfb' => $reads]);
    [$game, $forecast] = modelComparisonRetirementFixture();
    $counts = [CanonicalPrediction::count(), PredictionEvaluation::count()];
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $report = runRetiredModelComparison();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
    }
    expect(preg_match('/\b(insert into|update|delete from)\b/i', $queries))->toBe(0)
        ->and($queries)->not->toContain('cfb_predictions')
        ->and($report['legacy_comparison_included'])->toBeFalse()
        ->and($report['rows'][0]['legacy_comparable'])->toBeNull()
        ->and($report['rows'][0]['game_id'])->toBe($game->id)
        ->and($report['rows'][0]['prediction_id'])->toBe($forecast->public_id)
        ->and($report['rows'][0]['candidate'])->toBe(['winner_correct' => true, 'spread_absolute_error' => 0, 'total_absolute_error' => 0, 'brier_score' => 0.0625])
        ->and($report['summary']['official']['games'])->toBe(1)
        ->and([CanonicalPrediction::count(), PredictionEvaluation::count()])->toBe($counts);
})->with([[true, false], [false, true], [true, true]]);

it('retains explicit historical comparison and compatibility-mode flags', function (bool $pipeline, bool $reads, array $options) {
    config(['prediction_lifecycle.canonical_pipeline.cfb' => $pipeline, 'prediction_lifecycle.canonical_reads.cfb' => $reads]);
    modelComparisonRetirementFixture();
    $report = runRetiredModelComparison($options);
    expect($report['legacy_comparison_included'])->toBeTrue()->and($report['rows'][0]['legacy_comparable'])->toBeTrue();
})->with([[true, true, ['--include-legacy-comparison' => true]], [false, false, []]]);

it('preserves exclusion reasons without borrowing legacy evidence', function (string $variant, string $reason) {
    [$game] = modelComparisonRetirementFixture($variant);
    $report = runRetiredModelComparison();
    expect($report['excluded'][$game->id])->toBe($reason)
        ->and($report['rows'])->toBe([])->and($report['summary']['candidate']['spread_absolute_error'])->toBeNull();
})->with([
    ['missing_event', 'missing_event_start'], ['unsafe', 'missing_safe_evaluated_snapshot'],
    ['withdrawn', 'missing_safe_evaluated_snapshot'], ['at_kickoff', 'missing_safe_evaluated_snapshot'],
    ['missing_evaluation', 'missing_safe_evaluated_snapshot'],
    ['captured_at_kickoff', 'missing_safe_evaluated_snapshot'], ['source_at_kickoff', 'missing_safe_evaluated_snapshot'],
]);

it('loads canonical comparisons in bounded batches instead of per game', function () {
    foreach (range(1, 26) as $index) {
        modelComparisonRetirementFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $report = runRetiredModelComparison();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }
    expect($report['rows'])->toHaveCount(26)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'select "predictions".*')))->toHaveCount(2)
        ->and($queries->implode(' '))->not->toContain('cfb_predictions');
});

it('does not replace the latest unevaluated revision with an older evaluated forecast', function () {
    [$game, $older] = modelComparisonRetirementFixture();
    CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $game->sport_event_id,
        'calculation_run_id' => CalculationRun::factory()->create(['sport_event_id' => $game->sport_event_id,
            'event_input_snapshot_id' => $older->calculationRun->event_input_snapshot_id])->id, 'revision' => 2, 'phase' => 'pregame',
        'publication_state' => 'published', 'published_at' => $older->published_at]);
    $report = runRetiredModelComparison();
    expect($report['rows'])->toBe([])->and($report['excluded'][$game->id])->toBe('missing_safe_evaluated_snapshot');
});

it('keeps report grades identical when historical comparison is explicitly included', function () {
    modelComparisonRetirementFixture();
    $native = runRetiredModelComparison();
    $historical = runRetiredModelComparison(['--include-legacy-comparison' => true]);
    expect($native['summary'])->toBe($historical['summary'])
        ->and($native['rows'][0]['candidate'])->toBe($historical['rows'][0]['candidate'])
        ->and($native['rows'][0]['official'])->toBe($historical['rows'][0]['official']);
});
