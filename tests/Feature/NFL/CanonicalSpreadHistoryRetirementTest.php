<?php

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
use App\Services\NFL\NflEvidenceForecastSource;
use App\Services\NFL\NflSpreadAssessmentService;
use App\Services\NFL\NflSpreadValidationDataset;
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

function spreadHistoryRetirementFixture(string $variant = 'valid'): array
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
        'outputs' => ['win_probability' => 0.6, 'predicted_spread' => $variant === 'missing_value' ? null : 3, 'predicted_total' => $variant === 'missing_value' ? null : 44]];
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

it('uses frozen native recommendation margins with distinct lineage and no legacy reads', function (bool $pipeline, bool $reads) {
    [$game, $native, $legacy] = spreadHistoryRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $data = app(NflSpreadValidationDataset::class)->load(2026, 2026, NflModelVersion::current(), 'canonical');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($data['rows'])->toHaveCount(1)->and($data['rows'][0])->toMatchArray([
        'game_id' => $game->id, 'snapshot_id' => null, 'forecast_source' => 'canonical',
        'model_margin' => 3.0, 'actual_margin' => 7.0, 'home_line' => -2.5,
        'elo_market_margin' => null, 'validated_epa_market_margin' => null, 'line_freshness_verified' => true,
    ])->and($data['rows'][0]['canonical_lineage']['canonical_prediction_id'])->toBe($native->id)
        ->and($data['rows'][0]['canonical_lineage']['event_input_snapshot_id'])->toBe($native->calculationRun->event_input_snapshot_id)
        ->and($queries->implode(' '))->not->toContain('prediction_feature_snapshots')->not->toContain('nfl_predictions')->not->toContain('odds_data');
    expect($queries->filter(fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql)))->toBeEmpty();
    $legacyData = app(NflSpreadValidationDataset::class)->load(2026, 2026, NflModelVersion::current());
    expect($legacyData['rows'][0])->toMatchArray(['snapshot_id' => $legacy->id, 'model_margin' => 11.0, 'home_line' => -9.5]);
})->with(['pipeline' => [true, false], 'reads' => [false, true], 'both' => [true, true]]);

it('holds native residual history instead of borrowing historical or primary forecasts', function (string $variant) {
    spreadHistoryRetirementFixture($variant);
    $data = app(NflSpreadValidationDataset::class)->load(2026, 2026, NflModelVersion::current(), 'canonical');
    expect($data['rows'])->toBeEmpty()->and($data['games_seen'])->toBe(1)
        ->and(array_sum($data['excluded']))->toBe(1);
})->with(['withdrawn', 'draft', 'unsafe', 'missing_context', 'missing_value', 'missing_market', 'wrong_version', 'missing_start', 'invalid_start', 'kickoff_mismatch', 'kickoff_publication']);

it('uses provider quote times without upgrading stale or missing observations', function () {
    spreadHistoryRetirementFixture('quote_times');
    $row = app(NflSpreadValidationDataset::class)->load(2026, 2026, NflModelVersion::current(), 'canonical')['rows'][0];
    expect($row['line_freshness_verified'])->toBeFalse()
        ->and($row['quote_at'])->toBe(now()->subHours(66)->toIso8601String());
});

it('separates active residual caches and preserves unvalidated and minimum-sample holds', function () {
    spreadHistoryRetirementFixture();
    spreadHistoryRetirementFixture('withdrawn');
    $target = new Game(['season' => 2026]);
    $target->id = 9999;
    $target->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addDay()]));
    $service = app(NflSpreadAssessmentService::class);
    $native = $service->assess($target, 4, -2.5, NflModelVersion::current());
    expect($native)->toMatchArray(['sample_size' => 1, 'additional_samples_required' => 199,
        'cover_probability' => null, 'bet_eligible' => false, 'calibrated' => false,
        'history_source' => 'frozen_canonical_recommendation_same_model', 'validation_status' => 'insufficient_training_history']);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false]);
    expect($service->assess($target, 4, -2.5, NflModelVersion::current()))->toMatchArray([
        'history_source' => 'frozen_observed_pregame_same_model', 'sample_size' => 2,
    ]);
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    expect($service->assess($target, 4, -2.5, NflModelVersion::current()))->toEqual($native);
});

it('keeps native result-availability and target-exclusion guards', function (string $variant) {
    [$history] = spreadHistoryRetirementFixture();
    $target = new Game(['season' => 2026]);
    $target->id = $variant === 'target' ? $history->id : 9999;
    $target->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addDay()]));
    if ($variant !== 'target') {
        $history->forceFill(['result_updated_at' => match ($variant) {
            'at_forecast' => now(),
            'future' => now()->addHour(),
            'before_kickoff' => $history->sportEvent->starts_at->subMinute(),
        }])->saveQuietly();
    }
    expect(app(NflSpreadAssessmentService::class)->assess($target, 4, -2.5, NflModelVersion::current()))->toMatchArray([
        'sample_size' => 0, 'cover_probability' => null, 'calibrated' => false, 'bet_eligible' => false,
        'reason' => 'insufficient_prior_residuals',
    ]);
})->with(['at_forecast', 'future', 'before_kickoff', 'target']);
