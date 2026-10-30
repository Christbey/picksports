<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\NFL\NflCalibrationHistory;
use App\Services\NFL\NflEvidenceForecastSource;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\ModelRunRecorder;
use App\Support\NflModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => false]);
});
afterEach(fn () => $this->travelBack());

function adaptiveHistoryRetirementFixture(string $variant = 'valid'): array
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
        'features' => [], 'outputs' => ['win_probability' => 0.6, 'predicted_spread' => 11, 'predicted_total' => 44],
        'market_context' => ['bookmaker_home_line' => -9.5, 'market_home_margin' => 2.5, 'market_total' => 43.5, 'odds_updated_at' => $captured->copy()->subMinutes(5)->toIso8601String()],
        'model_metadata' => ['analysis_layer' => ['reason_codes' => ['qb_form_home_edge']]], 'feature_hash' => str_repeat('a', 64),
        'generated_at' => $generated, 'features_available_at' => $captured, 'game_start_at' => $start,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    $context = ['version' => NflRecommendationInputBuilder::VERSION,
        'evidence_source_version' => NflEvidenceForecastSource::VERSION,
        'runtime_configuration_hash' => app(ModelRunRecorder::class)->configHash('nfl'),
        'model_version' => $variant === 'wrong_version' ? 'other-model' : NflModelVersion::current(),
        'outputs' => ['win_probability' => 0.6, 'predicted_spread' => $variant === 'missing_value' ? null : 3, 'predicted_total' => $variant === 'missing_value' ? null : 44,
            'calibration_inputs' => $variant === 'missing_stage' ? [] : ['point' => ['predicted_spread' => 8, 'predicted_total' => 51],
                'win_probability' => ['win_probability' => $variant === 'invalid_probability' ? 2 : 0.85]]]];
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
            'missing_start' => null, 'invalid_start' => 'not-a-timestamp', 'kickoff_mismatch' => $start->copy()->addHour()->toIso8601String(), default => $start->toIso8601String(),
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

    $game->forceFill(['result_updated_at' => $start->copy()->addHours(4)])->saveQuietly();

    return [$game, $native, $legacy];
}

function adaptiveHistoryTarget(): Game
{
    $game = new Game(['season' => 2026, 'game_date' => now()->addDay()->toDateString()]);
    $game->id = 9999;
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addDay()]));

    return $game;
}

it('uses native stage inputs and published recommendation evidence with no legacy reads', function () {
    [$game, $native] = adaptiveHistoryRetirementFixture();
    $history = new NflCalibrationHistory;
    $target = adaptiveHistoryTarget();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $point = $history->prior($target, NflModelVersion::current(), 384, 'point', 'canonical')->sole();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::flushQueryLog();
        $win = $history->prior($target, NflModelVersion::current(), 512, 'win_probability', 'canonical')->sole();
        $published = $history->prior($target, NflModelVersion::current(), 272, forecastSource: 'canonical')->sole();
        expect(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect((float) $point->predicted_spread)->toBe(8.0)->and((float) $point->predicted_total)->toBe(51.0)
        ->and((float) $win->win_probability)->toBe(0.85)->and((float) $published->predicted_spread)->toBe(3.0)
        ->and($point->id)->toBeNull()->and($point->exists)->toBeFalse()
        ->and($point->canonical_lineage['canonical_prediction_id'])->toBe($native->id)
        ->and($point->game->id)->toBe($game->id)
        ->and($queries->implode(' '))->not->toContain('prediction_feature_snapshots')->not->toContain('nfl_predictions')->not->toContain('odds_data');
    expect((float) $history->prior($target, NflModelVersion::current(), 512)->sole()->predicted_spread)->toBe(11.0);
});

it('rejects ineligible native history without substituting legacy or primary forecasts', function (string $variant) {
    adaptiveHistoryRetirementFixture($variant);
    expect((new NflCalibrationHistory)->prior(adaptiveHistoryTarget(), NflModelVersion::current(), 512, forecastSource: 'canonical'))->toBeEmpty();
})->with(['withdrawn', 'draft', 'unsafe', 'missing_context', 'missing_value', 'wrong_version', 'missing_start', 'invalid_start', 'kickoff_mismatch', 'kickoff_publication']);

it('does not manufacture missing stages or accept an invalid stage probability', function (string $variant) {
    adaptiveHistoryRetirementFixture($variant);
    $history = new NflCalibrationHistory;
    $target = adaptiveHistoryTarget();
    expect($history->prior($target, NflModelVersion::current(), 512, 'win_probability', 'canonical'))->toBeEmpty()
        ->and($history->prior($target, NflModelVersion::current(), 512, forecastSource: 'canonical'))->toHaveCount(1);
    if ($variant === 'missing_stage') {
        expect($history->prior($target, NflModelVersion::current(), 384, 'point', 'canonical'))->toBeEmpty();
    }
})->with(['missing_stage', 'invalid_probability']);

it('preserves native result timing and regular-season holds', function (string $variant) {
    [$game] = adaptiveHistoryRetirementFixture();
    $game->forceFill(match ($variant) {
        'future_result' => ['result_updated_at' => now()->addHour()],
        'at_forecast' => ['result_updated_at' => now()],
        'before_kickoff' => ['result_updated_at' => $game->sportEvent->starts_at->copy()->subMinute()],
        'preseason' => ['season_type' => '1'],
        'missing_result' => ['result_updated_at' => null],
    })->saveQuietly();
    expect((new NflCalibrationHistory)->prior(adaptiveHistoryTarget(), NflModelVersion::current(), 512, forecastSource: 'canonical'))->toBeEmpty();
})->with(['future_result', 'at_forecast', 'before_kickoff', 'preseason', 'missing_result']);

it('keeps native minimum-sample holds in all three adaptive consumers and separates regime caches', function () {
    adaptiveHistoryRetirementFixture();
    $calculator = app(GeneratePredictionFromHistoricalElo::class);
    (new ReflectionProperty($calculator, 'calibrationForecastSource'))->setValue($calculator, 'canonical');
    $target = adaptiveHistoryTarget();
    config(['nfl.predictions.adaptive_point_calibration.enabled' => true, 'nfl.predictions.adaptive_win_probability_calibration.enabled' => true]);
    expect((new ReflectionMethod($calculator, 'applyAdaptivePointCalibration'))->invoke($calculator, $target, 3.0, 0.7, 44.0))->toBe([3.0, 0.7, 44.0]);
    expect((new ReflectionMethod($calculator, 'applyAdaptiveWinProbabilityCalibration'))->invoke($calculator, $target, 0.7))->toBe(0.7);
    $metadata = (new ReflectionProperty($calculator, 'lastModelMetadata'))->getValue($calculator);
    expect($metadata['adaptive_point_calibration']['reason'])->toBe('insufficient_prior_predictions')
        ->and($metadata['adaptive_win_probability_calibration']['reason'])->toBe('insufficient_verified_pregame_predictions');
    $regime = (new ReflectionMethod($calculator, 'highTrustRegimePerformance'))->invoke($calculator, $target, 0.9);
    expect($regime['reason'])->toBe('insufficient_high_trust_sample')->and($regime['applies'])->toBeFalse();
    (new ReflectionProperty($calculator, 'calibrationForecastSource'))->setValue($calculator, 'legacy');
    (new ReflectionMethod($calculator, 'highTrustRegimePerformance'))->invoke($calculator, $target, 0.9);
    expect((new ReflectionProperty($calculator, 'highTrustRegimePerformanceCache'))->getValue($calculator))->toHaveCount(2);
});

it('selects the correct history at the real calculator entry point', function (bool $pipeline, bool $reads, string $entry, string $source) {
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads,
        'nfl.predictions.adaptive_point_calibration.enabled' => true]);
    $game = Game::factory()->create(['season' => 2026, 'season_type' => '2', 'game_date' => now()->addDay()->toDateString(),
        'game_time' => '17:00:00', 'status' => 'STATUS_SCHEDULED', 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $history = Mockery::mock(NflCalibrationHistory::class);
    $history->shouldReceive('prior')->atLeast()->once()->withArgs(fn (...$args): bool => end($args) === $source)->andReturn(collect());
    $calculator = new GeneratePredictionFromHistoricalElo(calibrationHistory: $history);
    expect($calculator->{$entry}($game))->toBeArray();
})->with([
    'pipeline' => [true, false, 'previewPregame', 'canonical'],
    'reads' => [false, true, 'previewPregame', 'canonical'],
    'both' => [true, true, 'previewPregame', 'canonical'],
    'compatibility' => [false, false, 'previewPregame', 'legacy'],
    'historical preview' => [true, true, 'preview', 'legacy'],
]);
