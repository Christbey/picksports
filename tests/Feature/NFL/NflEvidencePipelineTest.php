<?php

use App\Models\BetDecision;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ModelPromotionEvaluator;
use App\Services\NFL\NflEvidenceCapture;
use App\Services\NFL\NflEvidenceComparison;
use App\Services\NFL\NflEvidenceLeaderboard;
use App\Services\NFL\NflEvidenceLedgerAudit;
use App\Services\NFL\NflEvidenceTrialService;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Chicago'));
    Storage::fake('ml-local');
    Storage::fake('ml-cache');
});

function pipelineSource(array $overrides = []): PredictionFeatureSnapshot
{
    $home = Team::firstOrCreate(['abbreviation' => 'PHI'], Arr::except(Team::factory()->make()->toArray(), ['abbreviation']));
    $away = Team::firstOrCreate(['abbreviation' => 'DAL'], Arr::except(Team::factory()->make()->toArray(), ['abbreviation']));
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => '2', 'week' => 8, 'game_date' => '2026-10-28', 'game_time' => '17:00:00',
        'status' => 'STATUS_SCHEDULED', ...($overrides['game'] ?? [])]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 3, 'predicted_total' => 44,
        'win_probability' => .6, 'model_version' => NflModelVersion::current()]);
    $run = app(ModelRunRecorder::class)->forPrediction('nfl', NflModelVersion::current(), 'test', 'test');
    $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game)->setTimezone(config('app.timezone'));

    return PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions',
        'prediction_id' => $prediction->id, 'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $run->id, 'model_version' => NflModelVersion::current(), 'feature_version' => 'test', 'blend_version' => 'test',
        'features' => [], 'outputs' => ['predicted_spread' => 3, 'predicted_total' => 44, 'win_probability' => .6, 'baseline_win_probability' => .5],
        'market_context' => ['market_home_margin' => 2.5, 'market_total' => 43.5, 'odds_updated_at' => now()->subMinutes(10)->toIso8601String()],
        'model_metadata' => ['analysis_layer' => ['reason_codes' => ['qb_form_home_edge']]], 'feature_hash' => str_repeat('a', 64),
        'generated_at' => now()->subMinute(), 'features_available_at' => now()->subMinute(), 'game_start_at' => $kickoff,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame', ...($overrides['snapshot'] ?? [])]);
}

function pipelineEvidence(PredictionFeatureSnapshot $source, string $state = 'matched'): PredictionFeatureSnapshot
{
    $conditions = ['catalog_56_home' => ['id' => 56, 'side' => 'home', 'label' => 'Passing mismatch', 'state' => $state,
        'definition' => ['id' => 56, 'version' => 'test', 'side' => 'home']]];
    $features = ['evidence' => ['policy' => NflEvidenceCapture::POLICY, 'conditions' => $conditions,
        'context' => ['cohort' => 'divisional', 'qb_status' => ['projected', 'projected']]]];
    $run = app(ModelRunRecorder::class)->forPrediction('nfl', $source->model_version, NflEvidenceCapture::POLICY, NflEvidenceCapture::POLICY);
    $snapshot = $source->replicate();
    $snapshot->snapshot_run_id = (string) Str::uuid();
    $snapshot->features = $features;
    $snapshot->generated_at = now();
    $snapshot->features_available_at = now();
    $snapshot->feature_hash = NflEvidenceCapture::fingerprint($features, $source->outputs, $source->market_context);
    $snapshot->model_run_id = $run->id;
    $snapshot->feature_version = NflEvidenceCapture::POLICY;
    $snapshot->blend_version = NflEvidenceCapture::POLICY;
    $snapshot->model_metadata = ['evidence_only' => true];
    $snapshot->unsetRelation('modelRun');
    $snapshot->save();

    return $snapshot->fresh();
}

function pipelineGrade(PredictionFeatureSnapshot $snapshot): void
{
    app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    foreach (NflSignalObservation::where('prediction_feature_snapshot_id', $snapshot->id)->get() as $observation) {
        app(NflSignalGradingService::class)->grade($observation);
    }
}

it('captures real pregame catalog states once and preserves published forecasts', function () {
    $source = pipelineSource();
    $before = Prediction::find($source->prediction_id)->getAttributes();
    $service = app(NflEvidenceCapture::class);
    $first = $service->capture(2026);
    $second = $service->capture(2026);
    expect($first['created'])->toBe(1)->and($first['observations_created'])->toBeGreaterThan(400)
        ->and($second['created'])->toBe(0)->and($second['existing'])->toBe(1)
        ->and(Prediction::find($source->prediction_id)->getAttributes())->toBe($before);
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflEvidenceCapture::POLICY)->first();
    expect(data_get($snapshot->features, 'evidence.phase'))->toBe('early')
        ->and(app(NflEvidenceTrialService::class)->safe($snapshot))->toBeTrue();
    expect(fn () => $snapshot->update(['outputs' => []]))->toThrow(LogicException::class);
});

it('never reconstructs capture after kickoff or accepts stale source forecasts', function () {
    pipelineSource(['game' => ['status' => 'STATUS_FINAL']]);
    pipelineSource(['snapshot' => ['generated_at' => now()->subHours(3), 'features_available_at' => now()->subHours(3)]]);
    $result = app(NflEvidenceCapture::class)->capture(2026);
    expect($result['created'])->toBe(0)->and(array_values($result['skipped']))->toContain('missing_fresh_verified_forecast');
});

it('keeps condition definitions stable across values/states and grades only known conditions', function () {
    $a = pipelineSource(['game' => ['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]]);
    $b = pipelineSource(['game' => ['status' => 'STATUS_FINAL', 'home_score' => 17, 'away_score' => 20]]);
    $c = pipelineSource(['game' => ['status' => 'STATUS_FINAL', 'home_score' => 17, 'away_score' => 20]]);
    pipelineGrade(pipelineEvidence($a));
    pipelineGrade(pipelineEvidence($b, 'not_matched'));
    pipelineGrade(pipelineEvidence($c, 'insufficient_data'));
    $observations = NflSignalObservation::where('signal_type', 'matchup_condition')->get();
    expect($observations->pluck('definition_hash')->unique())->toHaveCount(1);
    $report = app(NflEvidenceLeaderboard::class)->report(['from_season' => 2026, 'to_season' => 2026]);
    $row = collect($report['rows'])->where('condition_state', 'matched')->firstWhere('market', 'spread');
    expect($row['sample'])->toBe(1)->and($row['control_comparison']['control_games'])->toBe(1)
        ->and($row['control_comparison']['validated'])->toBeFalse();
    expect($observations->firstWhere('prediction_feature_snapshot_id', PredictionFeatureSnapshot::where('game_id', $c->game_id)->where('blend_version', NflEvidenceCapture::POLICY)->value('id'))->grades)->toHaveCount(0);
});

it('canonicalizes JSON numbers and rejects tampered evidence', function () {
    expect(NflEvidenceCapture::fingerprint(['a' => 1., 'b' => .123456789], [], []))
        ->toBe(NflEvidenceCapture::fingerprint(['b' => .12345679, 'a' => 1], [], []));
    $snapshot = pipelineEvidence(pipelineSource());
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['feature_hash' => str_repeat('f', 64)]);
    expect(fn () => app(NflSignalObservationMaterializer::class)->materialize($snapshot->fresh()))->toThrow(InvalidArgumentException::class);
});

it('reconciles missing snapshots, lines, materialization and grading without writes', function () {
    $snapshot = pipelineSource(['game' => ['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]]);
    $audit = app(NflEvidenceLedgerAudit::class);
    expect($audit->report(2026)['reasons']['winner']['not_materialized'])->toBe(1);
    app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    expect($audit->report(2026)['reasons']['winner']['grading_backlog'])->toBe(1);
    pipelineGrade($snapshot);
    expect($audit->report(2026)['reasons']['winner']['graded'])->toBe(1);
});

it('compares only common strata instead of inventing controls', function () {
    $service = app(NflEvidenceComparison::class);
    $row = ['result' => 'win', 'stratum' => 'one', 'comparison_loss' => .1];
    $control = ['result' => 'loss', 'stratum' => 'one', 'comparison_loss' => .3];
    $r = $service->compare([$row], [$control]);
    expect($r['common_strata'])->toBe(1)->and($r['comparison_improvement'])->toBeGreaterThan(.19);
    expect($service->compare([$row], [[...$control, 'stratum' => 'other']])['comparison_improvement'])->toBeNull();
});

it('preregisters immutable shadow protocols and records forecasts without changing the base', function () {
    $service = app(NflEvidenceTrialService::class);
    $artifact = $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()]);
    $source = pipelineSource();
    $snapshot = pipelineEvidence($source);
    expect($service->recordShadows($snapshot))->toBe(1)->and($service->recordShadows($snapshot))->toBe(0);
    $shadow = ShadowModelOutput::first();
    expect($shadow->baseline_output)->toBe(3.)->and($shadow->challenger_output)->toBe(3.25)
        ->and($shadow->explanation['candidate_ablations']['catalog_56_home'])->toEqual(3.)
        ->and(Prediction::find($source->prediction_id)->predicted_spread)->toBe('3.0');
    expect(fn () => $shadow->update(['challenger_output' => 7]))->toThrow(LogicException::class);
    expect(fn () => $artifact->trainingRun->update(['parameters' => []]))->toThrow(LogicException::class);
    $report = $service->evaluate($artifact->fresh());
    expect($report['status'])->toBe('awaiting_confirmation')->and($report['excluded']['pending_actual'])->toBe(1)
        ->and($report['promoted'])->toBeFalse();
});

it('refuses retrospective registration and unsafe or unknown shadow inputs', function () {
    $service = app(NflEvidenceTrialService::class);
    expect(fn () => $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->subDay()->toIso8601String(),
        'validation_end' => now()->addMonth()->toIso8601String()]))->toThrow(InvalidArgumentException::class);
    $artifact = $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()]);
    expect($service->recordShadows(pipelineEvidence(pipelineSource(), 'insufficient_data')))->toBe(0);
    $report = $service->evaluate($artifact);
    expect($report['checks']['validation_period_finished'])->toBeFalse()->and($report['periods']['validation']['games'])->toBe(0);
});

it('evaluates untouched later games separately with expanding windows and no promotion', function () {
    $service = app(NflEvidenceTrialService::class);
    $artifact = $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()]);
    foreach (['2026-11-01', '2026-11-10', '2026-11-17'] as $index => $date) {
        $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'America/Chicago'));
        $source = pipelineSource(['game' => ['game_date' => $date, 'week' => 9 + $index]]);
        $snapshot = pipelineEvidence($source);
        expect($service->recordShadows($snapshot))->toBe(1);
        Game::where('id', $snapshot->game_id)->update(['status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 20]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00:00', 'America/Chicago'));
    $r = $service->evaluate($artifact);
    expect($r['periods']['discovery']['games'])->toBe(1)->and($r['periods']['validation']['games'])->toBe(2)
        ->and($r['periods']['validation']['mean_improvement'])->toBe(.25)
        ->and($r['candidate_contributions']['catalog_56_home']['validation']['mean_full_minus_ablated_improvement'])->toBe(.25)
        ->and($r['expanding_validation_windows'])->toHaveCount(2)
        ->and($r['status'])->toBe('insufficient_evidence')->and($r['promoted'])->toBeFalse();
    expect(fn () => $artifact->update(['status' => 'promoted']))->toThrow(LogicException::class);
});

it('protects output integrity, skips a duplicate game and fails closed on protocol changes', function () {
    $service = app(NflEvidenceTrialService::class);
    $artifact = $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()]);
    $source = pipelineSource();
    $snapshot = pipelineEvidence($source);
    $service->recordShadows($snapshot);
    DB::table('shadow_model_outputs')->update(['challenger_output' => 3.8]);
    $r = $service->evaluate($artifact);
    expect($r['excluded']['invalid_lineage_or_period'])->toBe(1);
    DB::table('model_runs')->where('id', $artifact->training_run_id)->update(['parameters' => json_encode(['tampered' => true])]);
    expect(fn () => $service->evaluate($artifact->fresh()))->toThrow(InvalidArgumentException::class);
});

it('does not invent model sides for equal-line evidence forecasts', function () {
    $source = pipelineSource(['game' => ['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 20],
        'snapshot' => ['outputs' => ['win_probability' => .5, 'predicted_spread' => 2.5, 'predicted_total' => 43.5]]]);
    $snapshot = pipelineEvidence($source);
    pipelineGrade($snapshot);
    $grades = NflSignalObservation::where('prediction_feature_snapshot_id', $snapshot->id)->first()->grades;
    expect($grades->pluck('result_status')->unique()->all())->toBe(['no_pick'])
        ->and($grades->pluck('hit')->filter())->toHaveCount(0);
});

it('schedules bounded evidence capture after forecasts with locks and heartbeats', function () {
    $events = collect(app(Schedule::class)->events());
    $capture = $events->first(fn ($e) => str_contains($e->command ?? '', '--capture-evidence'));
    expect($capture)->not->toBeNull()->and($capture->expression)->toBe('35 6-23 * * *')
        ->and($capture->onOneServer)->toBeTrue()->and($capture->withoutOverlapping)->toBeTrue()
        ->and($capture->command)->toContain('--limit=16');
});

it('keeps evidence capture commands prospective and reconciliation read-only', function () {
    $this->artisan('nfl:materialize-signal-observations', ['--capture-evidence' => true])->assertFailed();
    $this->artisan('nfl:materialize-signal-observations', ['--capture-evidence' => true, '--season' => 2026, '--all-snapshots' => true])->assertFailed();
    $this->artisan('nfl:report-signal-grades', ['--reconcile' => true, '--season' => 2026])
        ->expectsOutputToContain('"read_only": true')->assertSuccessful();
});

it('uses bulk inserts and bounds capture count while allowing only early and late phases', function () {
    $source = pipelineSource();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service = app(NflEvidenceCapture::class);
    expect($service->capture(2026)['created'])->toBe(1);
    $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'insert') && str_contains($q['query'], 'nfl_signal_observations'));
    expect($inserts->count())->toBeLessThanOrEqual(6);
    DB::disableQueryLog();
    $this->travelTo(CarbonImmutable::parse('2026-10-28 11:00:00', 'America/Chicago'));
    expect($service->capture(2026)['created'])->toBe(1);
    expect($service->capture(2026)['created'])->toBe(0);
    expect(PredictionFeatureSnapshot::where('game_id', $source->game_id)->where('blend_version', NflEvidenceCapture::POLICY)->count())->toBe(2);
});

it('freezes absent atomic codes only when the source field is known', function () {
    $source = pipelineSource();
    app(NflSignalObservationMaterializer::class)->materialize($source);
    $source2 = pipelineSource(['snapshot' => ['model_metadata' => ['analysis_layer' => ['reason_codes' => []]]]]);
    $result = app(NflEvidenceCapture::class)->capture(2026);
    expect($result['created'])->toBe(2);
    $snapshot = PredictionFeatureSnapshot::where('game_id', $source2->game_id)->where('blend_version', NflEvidenceCapture::POLICY)->first();
    expect(data_get($snapshot->features, 'evidence.conditions.source_reason_code_qb_form_home_edge.state'))->toBe('not_matched');
    $observation = NflSignalObservation::where('prediction_feature_snapshot_id', $snapshot->id)->where('signal_key', 'source_reason_code_qb_form_home_edge')->first();
    expect($observation->signal_payload)->toHaveKey('source_path')->not->toHaveKey('condition');
});

it('keeps evidence trials out of betting decisions and generic promotion', function () {
    $service = app(NflEvidenceTrialService::class);
    $artifact = $service->register(['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()]);
    $service->recordShadows(pipelineEvidence(pipelineSource()));
    $this->artisan('sports:record-shadow-bet-decisions', ['--sport' => 'nfl', '--artifact' => $artifact->id])->assertSuccessful();
    expect(BetDecision::count())->toBe(0);
    $promotion = app(ModelPromotionEvaluator::class)->evaluate($artifact, '/does/not/need/a/report');
    expect($promotion['eligible'])->toBeFalse();
    $this->artisan('nfl:evidence-trial', ['action' => 'deactivate', '--artifact' => $artifact->id])->assertSuccessful();
    expect($artifact->fresh()->status)->toBe('retired');
});

it('registers identical protocols once and rejects attempts to weaken gates', function () {
    $service = app(NflEvidenceTrialService::class);
    $spec = ['model_version' => NflModelVersion::current(), 'market' => 'spread',
        'candidates' => ['catalog_56_home' => .25], 'discovery_end' => now()->addDays(8)->toIso8601String(),
        'validation_end' => now()->addDays(36)->toIso8601String()];
    expect($service->register($spec)->id)->toBe($service->register($spec)->id);
    expect(ModelArtifact::where('model_type', NflEvidenceTrialService::TYPE)->count())->toBe(1);
    expect(fn () => $service->register([...$spec, 'minimum_games_per_period' => 1]))->toThrow(InvalidArgumentException::class);
});
