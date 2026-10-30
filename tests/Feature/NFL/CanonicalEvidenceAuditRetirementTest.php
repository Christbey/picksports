<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\NFL\NflEvidenceLedgerAudit;
use App\Services\NFL\NflEvidenceOperations;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\ModelRunRecorder;
use App\Support\NflModelVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
    Cache::flush();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => false]);
});
afterEach(fn () => $this->travelBack());

function evidenceAuditRetirementFixture(string $variant = 'valid'): array
{
    $start = now()->subDay()->utc();
    $generated = now()->subHours(26);
    $captured = now()->subHours(27);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => '2',
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(), 'status' => 'STATUS_FINAL',
        'home_score' => 27, 'away_score' => 20, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'odds_data' => ['unused' => str_repeat('large', 1000)]]);
    $start = $event->refresh()->starts_at;
    $legacyPrediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 3, 'predicted_total' => 44,
        'win_probability' => 0.6, 'model_version' => NflModelVersion::current()]);
    $modelRun = app(ModelRunRecorder::class)->forPrediction('nfl', NflModelVersion::current(), 'test', 'test');
    $legacy = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions',
        'prediction_id' => $legacyPrediction->id, 'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRun->id, 'model_version' => NflModelVersion::current(), 'feature_version' => 'test', 'blend_version' => 'test',
        'features' => [], 'outputs' => ['win_probability' => 0.6, 'predicted_spread' => 3, 'predicted_total' => 44],
        'market_context' => ['market_home_margin' => 2.5, 'market_total' => 43.5, 'odds_updated_at' => $captured->copy()->subMinutes(5)->toIso8601String()],
        'model_metadata' => ['analysis_layer' => ['reason_codes' => ['qb_form_home_edge']]], 'feature_hash' => str_repeat('a', 64),
        'generated_at' => $generated, 'features_available_at' => $captured, 'game_start_at' => $start,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    app(NflSignalObservationMaterializer::class)->materialize($legacy);
    foreach (NflSignalObservation::query()->get() as $observation) {
        app(NflSignalGradingService::class)->grade($observation);
    }
    $context = ['version' => NflRecommendationInputBuilder::VERSION,
        'model_version' => $variant === 'wrong_version' ? 'other-model' : NflModelVersion::current(),
        'outputs' => ['win_probability' => 0.6, 'predicted_spread' => 3, 'predicted_total' => $variant === 'missing_value' ? null : 44]];
    $market = ['available' => $variant !== 'missing_market', 'consensus' => [
        'moneyline' => ['freshness_observed_at' => $captured->copy()->subMinutes(5)->toIso8601String()],
        'spread' => ['line' => -2.5, 'captured_at' => $captured->toIso8601String(),
            'provider_observed_at' => $variant === 'quote_times' ? $generated->copy()->subHours(40)->toIso8601String() : $captured->copy()->subMinutes(5)->toIso8601String()],
        'total' => ['line' => 43.5, 'provider_observed_at' => $variant === 'quote_times' ? $generated->copy()->addMinute()->toIso8601String() : $captured->copy()->subMinutes(5)->toIso8601String()],
    ]];
    $input = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id,
        'captured_at' => $captured, 'cutoff_at' => $captured, 'latest_source_available_at' => $captured->copy()->subHour(),
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => ['event' => ['starts_at' => match ($variant) {
            'missing_start' => null, 'invalid_start' => 'not-a-timestamp', default => $start->toIso8601String(),
        }], 'recommendation_context' => $variant === 'missing_context' ? [] : $context,
            'pregame_market' => $market, 'unused' => str_repeat('large', 1000)]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nfl', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => $generated, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -7.0, null], ['total', 'combined', 55.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $variant === 'kickoff_publication' ? $start : $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native, $legacy];
}

it('audits frozen canonical recommendation evidence without treating colliding historical grades as native', function (bool $pipeline, bool $reads) {
    [$game, $native, $legacy] = evidenceAuditRetirementFixture();
    expect($native->id)->toBe($legacy->id);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    DB::enableQueryLog();
    try {
        $report = app(NflEvidenceLedgerAudit::class)->report(2026);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['forecast_source'])->toBe('canonical')->and($report['canonical_evidence_capture'])->toBe('not_migrated')
        ->and($report['reasons']['winner'])->toBe(['not_materialized' => 1])
        ->and($report['games'][0]['snapshot_id'])->toBeNull()
        ->and($report['games'][0]['canonical_prediction_public_id'])->toBe($native->public_id)
        ->and($report['games'][0]['game_id'])->toBe($game->id)
        ->and($report['games'][0]['event_input_snapshot_id'])->toBe($native->calculationRun->event_input_snapshot_id)
        ->and($report['freshness']['last_forecast']->eq($native->generated_at))->toBeTrue()
        ->and($queries->implode(' '))->not->toContain('MAX(id)')->not->toContain('snapshot_metadata_payloads');
    expect($queries->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete)\b/i', $query)))->toBeEmpty();
    $inputQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "event_input_snapshots"') && ! str_contains($query, 'from "predictions"'));
    expect($inputQueries)->toHaveCount(1);
    expect($inputQueries->first())->not->toContain('select *')->not->toContain('model_metadata');
})->with(['pipeline only' => [true, false], 'reads only' => [false, true], 'both' => [true, true]]);

it('retains native audit gaps instead of using legacy forecasts', function (string $variant) {
    evidenceAuditRetirementFixture($variant);
    $report = app(NflEvidenceLedgerAudit::class)->report(2026);
    expect($report['reasons']['winner']['missing_verified_pregame_snapshot'])->toBe(1)
        ->and($report['freshness']['last_forecast'])->toBeNull()
        ->and($report['games'][0]['canonical_prediction_id'])->toBeNull();
})->with(['draft', 'withdrawn', 'unsafe', 'wrong_version', 'missing_context', 'kickoff_publication', 'missing_start', 'invalid_start']);

it('keeps recommendation evidence separate from primary projections and mutable market coverage', function (string $variant, string $market, string $reason) {
    evidenceAuditRetirementFixture($variant);
    $report = app(NflEvidenceLedgerAudit::class)->report(2026);
    expect($report['reasons'][$market][$reason])->toBe(1);
})->with([
    ['missing_value', 'total', 'missing_forecast_value'],
    ['missing_market', 'spread', 'missing_market_line'],
]);

it('classifies per-market provider observation times against the frozen forecast', function () {
    evidenceAuditRetirementFixture('quote_times');
    $rows = collect(app(NflEvidenceLedgerAudit::class)->report(2026)['games'])->keyBy('market');
    expect($rows['winner']['quote_status'])->toBe('fresh')
        ->and($rows['spread']['quote_status'])->toBe('stale')
        ->and($rows['total']['quote_status'])->toBe('after_forecast');
});

it('restores historical audit grading when both canonical switches are off', function () {
    evidenceAuditRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false, 'prediction_lifecycle.canonical_reads.nfl' => false]);
    $report = app(NflEvidenceLedgerAudit::class)->report(2026);
    expect($report['forecast_source'])->toBe('legacy')
        ->and($report['reasons']['winner']['graded'])->toBe(1)
        ->and($report['games'][0]['snapshot_id'])->not->toBeNull()
        ->and($report['games'][0])->not->toHaveKey('canonical_prediction_id');
});

it('isolates operations caches when forecast sources change', function () {
    evidenceAuditRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false, 'prediction_lifecycle.canonical_reads.nfl' => false]);
    $originalEnvironment = app()->environment();
    app()->instance('env', 'production');
    try {
        $legacy = app(NflEvidenceOperations::class)->report(2026, NflModelVersion::current());
        config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
        $native = app(NflEvidenceOperations::class)->report(2026, NflModelVersion::current());
    } finally {
        app()->instance('env', $originalEnvironment);
    }
    expect($legacy['audit']['forecast_source'])->toBe('legacy')
        ->and($native['audit']['forecast_source'])->toBe('canonical')
        ->and($native['audit']['reasons']['winner']['not_materialized'])->toBe(1);
});

it('retains the frozen kickoff mismatch hold when an event is rescheduled', function () {
    [$game] = evidenceAuditRetirementFixture();
    $event = $game->sportEvent;
    $event->update(['starts_at' => $event->starts_at->addHours(2)]);
    $report = app(NflEvidenceLedgerAudit::class)->report(2026);
    expect($report['reasons']['winner']['kickoff_mismatch'])->toBe(1)
        ->and($report['reasons']['spread']['kickoff_mismatch'])->toBe(1)
        ->and($report['reasons']['total']['kickoff_mismatch'])->toBe(1);
});
