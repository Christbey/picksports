<?php

use App\Models\BetDecision;
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
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use App\Services\NFL\NflEvidenceCapture;
use App\Services\NFL\NflEvidenceForecastSource;
use App\Services\NFL\NflEvidenceLeaderboard;
use App\Services\NFL\NflEvidenceLedgerAudit;
use App\Services\NFL\NflEvidenceTrialService;
use App\Services\NFL\NflSignalGradeReportService;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\ModelRunRecorder;
use App\Support\NflModelVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-29 10:00:00');
    Cache::flush();
    Http::preventStrayRequests();
    Storage::fake('ml-local');
    Storage::fake('ml-cache');
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => false]);
});
afterEach(fn () => $this->travelBack());

function captureRetirementCatalog(Game $game): array
{
    return ['version' => 'test-catalog-v1', 'baseline' => [],
        'signals' => [['id' => 56, 'offense_team_id' => $game->home_team_id, 'label' => 'Passing mismatch', 'status' => 'matched', 'evidence' => []]],
        'catalog' => [['id' => 56, 'label' => 'Passing mismatch', 'support' => 'implemented']]];
}

function captureRetirementFixture(string $variant = 'valid', bool $legacy = false): array
{
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026, 'starts_at' => now()->addHours(3)]);
    $start = $event->refresh()->starts_at;
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => '2', 'week' => 9,
        'game_date' => $start->utc()->toDateString(), 'game_time' => $start->utc()->toTimeString(), 'status' => 'STATUS_SCHEDULED',
        'home_score' => null, 'away_score' => null, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $start = $event->refresh()->starts_at;
    $generated = $variant === 'stale' ? now()->subHours(3) : now()->subMinute();
    $captured = $generated->copy()->subMinutes(5);
    $context = ['version' => NflRecommendationInputBuilder::VERSION, 'evidence_source_version' => NflEvidenceForecastSource::VERSION,
        'runtime_configuration_hash' => $variant === 'missing_hash' ? null : app(ModelRunRecorder::class)->configHash('nfl'),
        'model_version' => NflModelVersion::current(), 'outputs' => ['win_probability' => 0.6, 'predicted_spread' => 3.0, 'predicted_total' => 44.0],
        'model_metadata' => ['analysis_layer' => ['reason_codes' => ['qb_form_home_edge'], 'risk_flags' => []],
            'qb_form' => ['home' => ['qb_id' => 'frozen-qb', 'identity_status' => 'projected']]]];
    $inputs = ['event' => ['game_id' => $variant === 'wrong_game' ? $game->id + 1000 : $game->id,
        'starts_at' => ($variant === 'kickoff_mismatch' ? $start->addHour() : $start)->toIso8601String()],
        'recommendation_context' => $variant === 'missing_context' ? [] : $context,
        'pregame_market' => ['available' => true, 'consensus' => [
            'moneyline' => ['freshness_observed_at' => $captured->copy()->subMinute()->toIso8601String()],
            'spread' => ['line' => -2.5, 'freshness_observed_at' => $captured->copy()->subMinute()->toIso8601String()],
            'total' => ['line' => 43.5, 'freshness_observed_at' => $captured->copy()->subMinute()->toIso8601String()],
        ]]];
    $input = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'captured_at' => $captured,
        'cutoff_at' => $start, 'latest_source_available_at' => $captured->copy()->subMinute(),
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => $inputs, 'content_hash' => app(CanonicalPayloadHasher::class)->hash($inputs)]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nfl', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $generated,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -7.0, null], ['total', 'combined', 55.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type, 'selection' => $side,
            'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => $generated]);
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }
    $old = null;
    if ($legacy) {
        $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 3, 'predicted_total' => 44,
            'win_probability' => 0.6, 'model_version' => NflModelVersion::current()]);
        $modelRun = app(ModelRunRecorder::class)->forPrediction('nfl', NflModelVersion::current(), NflEvidenceCapture::POLICY, NflEvidenceCapture::POLICY);
        $features = ['evidence' => ['policy' => NflEvidenceCapture::POLICY, 'phase' => 'early',
            'context' => ['cohort' => 'unknown', 'qb_status' => ['unknown', 'unknown']],
            'conditions' => ['catalog_56_home' => ['label' => 'Passing mismatch', 'state' => 'matched', 'definition' => ['id' => 56]]]]];
        $outputs = ['win_probability' => 0.55, 'predicted_spread' => 2, 'predicted_total' => 42];
        $market = ['market_home_margin' => 2.5, 'market_total' => 43.5];
        $old = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => $prediction->id,
            'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $modelRun->id,
            'model_version' => NflModelVersion::current(), 'feature_version' => NflEvidenceCapture::POLICY, 'blend_version' => NflEvidenceCapture::POLICY,
            'features' => $features, 'outputs' => $outputs, 'market_context' => $market,
            'feature_hash' => NflEvidenceCapture::fingerprint($features, $outputs, $market),
            'generated_at' => now(), 'features_available_at' => now(), 'game_start_at' => $start,
            'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
        app(NflSignalObservationMaterializer::class)->materialize($old);
    }
    $catalog = Mockery::mock(app()->build(NflMatchupSignalService::class));
    $catalog->shouldReceive('build')->andReturnUsing(fn (Game $game): array => captureRetirementCatalog($game));
    app()->instance(NflMatchupSignalService::class, $catalog);

    return [$game, $native, $input, $old];
}

it('captures native evidence with explicit lineage while preserving colliding historical identities', function () {
    [$game, $native, $input, $old] = captureRetirementFixture(legacy: true);
    $before = $native->fresh()->getRawOriginal();
    $result = app(NflEvidenceCapture::class)->capture(2026);
    $capture = PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->sole();
    $observations = NflSignalObservation::where('prediction_feature_snapshot_id', $capture->id)->get();
    expect($result['created'])->toBe(1)->and($capture->prediction_table)->toBe('predictions')
        ->and($capture->canonical_prediction_id)->toBe($native->id)->and($capture->prediction_id)->toBe($native->id)
        ->and($observations->every(fn ($row): bool => $row->prediction_id === null && $row->canonical_prediction_id === $native->id))->toBeTrue()
        ->and($capture->outputs['predicted_total'])->toBe(44)->and($capture->outputs['win_probability'])->toBe(0.6)
        ->and(data_get($capture->features, 'evidence.canonical_lineage.event_input_snapshot_id'))->toBe($input->id)
        ->and(data_get($capture->features, 'evidence.source_snapshot_id'))->toBeNull()
        ->and(data_get($capture->features, 'evidence.context.home_qb'))->toBe('frozen-qb')
        ->and($native->fresh()->getRawOriginal())->toBe($before)
        ->and(NflSignalObservation::where('prediction_feature_snapshot_id', $old->id)->first()->prediction_id)->not->toBeNull()
        ->and(BetDecision::count())->toBe(0)->and(ShadowModelOutput::count())->toBe(0);
    expect(app(NflEvidenceCapture::class)->capture(2026)['existing'])->toBe(1)
        ->and(PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->count())->toBe(1);
    $this->travelTo(now()->addMinutes(100));
    expect(app(NflEvidenceCapture::class)->capture(2026)['created'])->toBe(1)
        ->and(PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->count())->toBe(2);
});

it('does not use legacy captures or forecasts when native evidence is ineligible', function (string $variant) {
    [, , , $old] = captureRetirementFixture($variant, legacy: true);
    $result = app(NflEvidenceCapture::class)->capture(2026);
    expect($result['created'])->toBe(0)->and($result['existing'])->toBe(0)
        ->and($result['skipped'])->toContain('missing_fresh_verified_forecast')
        ->and(PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->count())->toBe(0)
        ->and($old->fresh())->not->toBeNull();
})->with(['withdrawn', 'unsafe', 'stale', 'missing_hash', 'missing_context', 'wrong_game', 'kickoff_mismatch']);

it('preserves source configuration drift and prospective kickoff holds', function () {
    [$game] = captureRetirementFixture();
    config(['nfl.elo.default' => (float) config('nfl.elo.default', 1500) + 1]);
    expect(app(NflEvidenceCapture::class)->capture(2026)['skipped'][$game->id])->toBe('source_configuration_changed');
    $this->travelTo($game->sportEvent->starts_at->addMinute());
    expect(app(NflEvidenceCapture::class)->capture(2026)['created'])->toBe(0)
        ->and(PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->count())->toBe(0);
});

it('rechecks native publication and configuration after building capture conditions', function (string $change) {
    [$game, $native] = captureRetirementFixture();
    $catalog = Mockery::mock(app()->build(NflMatchupSignalService::class));
    $catalog->shouldReceive('build')->once()->andReturnUsing(function (Game $game) use ($native, $change): array {
        if ($change === 'withdrawn') {
            $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
        } else {
            config(['nfl.elo.default' => (float) config('nfl.elo.default', 1500) + 1]);
        }

        return captureRetirementCatalog($game);
    });
    app()->instance(NflMatchupSignalService::class, $catalog);
    expect(app(NflEvidenceCapture::class)->capture(2026)['skipped'][$game->id])->toBe('source_changed_during_capture')
        ->and(PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->count())->toBe(0);
})->with(['withdrawn', 'config']);

it('grades native captured outcomes and reconciles their exact ledger without using legacy grades', function () {
    [$game, $native] = captureRetirementFixture(legacy: true);
    app(NflEvidenceCapture::class)->capture(2026);
    $capture = PredictionFeatureSnapshot::where('canonical_prediction_id', $native->id)->sole();
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->travelTo($game->sportEvent->starts_at->addHours(3));
    $audit = app(NflEvidenceLedgerAudit::class)->report(2026);
    expect($audit['reasons']['winner']['grading_backlog'])->toBe(1)->and($audit['games'][0]['snapshot_id'])->toBe($capture->id);
    foreach (NflSignalObservation::query()->get() as $observation) {
        app(NflSignalGradingService::class)->grade($observation);
    }
    expect(app(NflEvidenceLedgerAudit::class)->report(2026)['reasons']['winner']['graded'])->toBe(1);
    $nativeReport = app(NflSignalGradeReportService::class)->report(['forecast_source' => 'canonical']);
    $legacyReport = app(NflSignalGradeReportService::class)->report(['forecast_source' => 'legacy']);
    expect($nativeReport['forecast_source'])->toBe('canonical')->and($nativeReport['signals'])->not->toBeEmpty()
        ->and($legacyReport['forecast_source'])->toBe('legacy')->and($legacyReport['signals'])->not->toBeEmpty();
    $leaderboard = app(NflEvidenceLeaderboard::class)->report(['forecast_source' => 'canonical']);
    expect($leaderboard['scope']['forecast_source'])->toBe('canonical')->and(BetDecision::count())->toBe(0);
    $this->artisan('nfl:materialize-signal-observations', ['--snapshot-id' => [$capture->id]])->assertSuccessful();
    $this->artisan('nfl:report-signal-grades', ['--forecast-source' => 'legacy', '--json' => true])->expectsOutputToContain('"forecast_source": "legacy"')->assertSuccessful();
});

it('withholds legacy evidence-trial protocols from native captures', function () {
    captureRetirementFixture();
    $trials = app(NflEvidenceTrialService::class);
    $trials->register(['model_version' => NflModelVersion::current(), 'market' => 'spread', 'candidates' => ['catalog_56_home' => 0.25],
        'discovery_end' => now()->addDays(8)->toIso8601String(), 'validation_end' => now()->addDays(36)->toIso8601String()]);
    app(NflEvidenceCapture::class)->capture(2026);
    $capture = PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::NATIVE_POLICY)->sole();
    expect($trials->recordShadows($capture))->toBe(0)->and(ShadowModelOutput::count())->toBe(0)->and(BetDecision::count())->toBe(0);
});

it('bounds native capture count and refuses destructive lineage rollback', function () {
    captureRetirementFixture();
    captureRetirementFixture();
    expect(app(NflEvidenceCapture::class)->capture(2026, 1)['created'])->toBe(1);
    $migration = require database_path('migrations/2026_10_29_193510_add_canonical_lineage_to_nfl_evidence.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve native NFL evidence lineage')
        ->and(Schema::hasColumn('nfl_signal_observations', 'canonical_prediction_id'))->toBeTrue()
        ->and(Schema::hasColumn('prediction_feature_snapshots', 'canonical_prediction_id'))->toBeTrue();
});

it('grades frozen native captures after source withdrawal without allowing another capture', function () {
    [$game, $native] = captureRetirementFixture();
    app(NflEvidenceCapture::class)->capture(2026);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->travelTo(now()->addMinutes(100));
    expect(app(NflEvidenceCapture::class)->capture(2026)['created'])->toBe(0);
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->travelTo($game->sportEvent->starts_at->addHours(3));
    $observation = NflSignalObservation::where('canonical_prediction_id', $native->id)->where('signal_key', 'catalog_56_home')->firstOrFail();
    expect(app(NflSignalGradingService::class)->grade($observation)['created'])->toBeGreaterThan(0)
        ->and(BetDecision::count())->toBe(0)
        ->and(PredictionFeatureSnapshot::where('canonical_prediction_id', $native->id)->count())->toBe(1);
});
