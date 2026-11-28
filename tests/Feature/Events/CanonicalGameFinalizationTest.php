<?php

use App\Actions\Validation\Checks\FinalizedDataCompletenessCheck;
use App\Events\GameFinalized;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\NBA\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Services\GameFinalizationDispatcher;
use App\Services\PerformanceStatistics;
use App\Services\Predictions\CanonicalPredictionEvaluator;
use App\Services\Predictions\CanonicalTeamGameEvaluator;
use App\Services\Predictions\SportEventResultRecorder;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\CFB\CfbGameStart;
use App\Support\Sports\CanonicalEventStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ObservedMlbGameFixture;

function canonicalFinalizationFixture(string $sport = 'nba', bool $withRun = false, ?CarbonImmutable $kickoffAt = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true,
        "prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $start = $kickoffAt ?? now()->subHours(4);
    $captureAt = CarbonImmutable::instance($start)->subMinutes(11);
    [$event, $game] = CarbonImmutable::withTestNow($captureAt, function () use ($sport, $start): array {
        $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => $start]);
        $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
        $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
        $game = $gameClass::factory()->create([
            'home_team_id' => $teamClass::factory()->create()->id,
            'away_team_id' => $teamClass::factory()->create()->id,
            'sport_event_id' => $event->id, 'status' => 'STATUS_FINAL',
            'game_date' => $start->copy()->utc()->toDateString(), 'game_time' => $start->copy()->utc()->toTimeString(),
            ...($sport === 'cfb' ? ['espn_event_id' => 'canonical-final-'.$event->id,
                ...CfbGameStart::attributes($start->copy()->utc()->toIso8601String(), [], 'espn_scoreboard', 'canonical-final-'.$event->id)] : []),
            'home_score' => 108, 'away_score' => 100,
            ...($sport !== 'mlb' ? ['game_clock' => '0:00'] : []),
        ]);
        if ($game instanceof Game) {
            $game = ObservedMlbGameFixture::observe($game, $start);
        }

        return [$event, $game];
    });
    // Native finalization fixtures must contain the same frozen identity and
    // completed calculation evidence as a real published forecast.
    $generatedAt = $start->copy()->subMinutes(10);
    $inputAttributes = [
        'sport' => $sport, 'sport_event_id' => $event->id,
        'captured_at' => $generatedAt->copy()->subMinute(),
        'cutoff_at' => $generatedAt->copy()->subMinute(),
        'latest_source_available_at' => $generatedAt->copy()->subMinutes(2),
        'inputs' => ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]],
    ];
    if ($game instanceof Game) {
        $captureAt = CarbonImmutable::instance($generatedAt)->subMinute();
        $inputAttributes = ObservedMlbGameFixture::snapshotAttributes($game, $inputAttributes['inputs'], $captureAt);
    }
    $input = EventInputSnapshot::factory()->create($inputAttributes);
    $release = CalculationRelease::factory()->approved()->create([
        'sport' => $sport, 'phase' => 'pregame',
        'approved_at' => $generatedAt->copy()->subMinutes(2),
        'effective_at' => $generatedAt->copy()->subMinutes(2),
    ]);
    $outputHash = hash('sha256', json_encode(['home_probability' => 0.65, 'home_line' => -6.5, 'total' => 210.5]));
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'phase' => 'pregame',
        'status' => 'succeeded', 'output_hash' => $outputHash,
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'output_hash' => $outputHash,
        'phase' => 'pregame', 'publication_state' => 'draft',
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
        'generated_at' => $start->copy()->subMinutes(10),
        'output_metadata' => ['market_conventions' => ['spread' => 'sportsbook_home_line']],
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id,
        'market_type' => 'moneyline', 'selection' => 'home', 'probability' => 0.65]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id,
        'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -6.5]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id,
        'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 210.5]);
    $prediction->update(['publication_state' => 'published', 'published_at' => $start->copy()->subMinute()]);

    return [$game, $prediction->refresh()];
}

function handleCanonicalFinalization($game): void
{
    app(TriggerGameFinalizationGrading::class)->handle(new GameFinalized(
        sport: $game->sportEvent->sport, gameId: $game->id,
        season: $game->season, gameModelClass: $game::class,
    ));
}

it('discovers and evaluates canonical-only final games independently of the public read switch', function (string $sport) {
    Event::fake([GameFinalized::class]);
    [$game, $prediction] = canonicalFinalizationFixture($sport);
    $before = $prediction->getAttributes();
    $dispatcher = app(GameFinalizationDispatcher::class);
    $dispatcher->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    expect(SportEventResult::query()->count())->toBe(0);

    handleCanonicalFinalization($game);
    handleCanonicalFinalization($game);
    $dispatcher->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    $evaluation = PredictionEvaluation::query()->sole();
    expect($evaluation->canonical_prediction_id)->toBe($prediction->id)
        ->and($evaluation->actuals['home_margin'])->toBe(8)
        ->and($evaluation->actuals['total_points'])->toBe(208)
        ->and($evaluation->errors['spread_absolute_error'])->toBe(1.5)
        ->and(SportEventResult::query()->count())->toBe(1)
        ->and($prediction->fresh()->getAttributes())->toBe($before)
        ->and($game->prediction()->exists())->toBeFalse();
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wnba', 'wcbb']);

it('appends corrected and reverted final results without rewriting earlier evaluations', function () {
    Event::fake([GameFinalized::class]);
    [$game] = canonicalFinalizationFixture();
    handleCanonicalFinalization($game);
    $first = PredictionEvaluation::query()->sole();
    $before = $first->getAttributes();
    foreach ([109, 108] as $score) {
        $game->update(['home_score' => $score]);
        app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
        handleCanonicalFinalization($game);
    }
    Event::assertDispatchedTimes(GameFinalized::class, 2);
    $results = SportEventResult::query()->orderBy('revision')->get();
    $evaluations = PredictionEvaluation::query()->orderBy('evaluation_revision')->get();
    expect($results)->toHaveCount(3)
        ->and($results->pluck('home_score')->all())->toBe([108, 109, 108])
        ->and($results[2]->supersedes_sport_event_result_id)->toBe($results[1]->id)
        ->and($evaluations)->toHaveCount(3)
        ->and($evaluations[2]->supersedes_prediction_evaluation_id)->toBe($evaluations[1]->id)
        ->and($evaluations[2]->sport_event_result_id)->toBe($results[2]->id)
        ->and($first->fresh()->getAttributes())->toBe($before);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 2);
});

it('waits for complete final scores and clock before rediscovering canonical work', function () {
    Event::fake([GameFinalized::class]);
    [$game] = canonicalFinalizationFixture();
    $game->update(['home_score' => null]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    handleCanonicalFinalization($game);
    expect(PredictionEvaluation::query()->count())->toBe(0);
    $game->update(['home_score' => 108, 'game_clock' => '4:21']);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertNotDispatched(GameFinalized::class);
    $game->update(['game_clock' => '0:00']);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    handleCanonicalFinalization($game);
    expect(PredictionEvaluation::query()->count())->toBe(1);
});

it('respects the canonical pipeline switch while preserving legacy grading', function () {
    Event::fake([GameFinalized::class]);
    [$game] = canonicalFinalizationFixture();
    config(['prediction_lifecycle.canonical_pipeline.nba' => false, 'prediction_lifecycle.canonical_reads.nba' => true]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertNotDispatched(GameFinalized::class);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => null]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    handleCanonicalFinalization($game);
    expect($legacy->fresh()->graded_at)->not->toBeNull()
        ->and(PredictionEvaluation::query()->whereNotNull('canonical_prediction_id')->count())->toBe(0);
});

it('does not discover withdrawn forecasts as canonical finalization work', function (string $state) {
    Event::fake([GameFinalized::class]);
    [$game, $prediction] = canonicalFinalizationFixture();
    $prediction->update(['publication_state' => $state, 'withdrawn_at' => now()]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertNotDispatched(GameFinalized::class);
    handleCanonicalFinalization($game);
    expect(PredictionEvaluation::query()->count())->toBe(0);
})->with(['withdrawn']);

it('waits for the existing minimum final age and evaluates the latest persisted scores', function () {
    Event::fake([GameFinalized::class]);
    [$game] = canonicalFinalizationFixture();
    $game->sportEvent->update(['starts_at' => now()->subMinutes(30)]);
    expect(app(CanonicalTeamGameEvaluator::class)->hasPendingEvaluation($game, 'nba'))->toBeFalse();
    $this->travel(61)->minutes();
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    $game->newQuery()->whereKey($game->id)->update(['home_score' => 111]);
    handleCanonicalFinalization($game);
    expect(PredictionEvaluation::query()->sole()->actuals['home_score'])->toBe(111);
});

it('rediscovers missing or outdated canonical evaluations for an already recorded result', function (bool $oldEvaluation) {
    Event::fake([GameFinalized::class]);
    [$game, $prediction] = canonicalFinalizationFixture();
    $result = app(SportEventResultRecorder::class)->record(
        $game->sportEvent, 108, 100, 'espn', (string) $game->id,
    );
    if ($oldEvaluation) {
        PredictionEvaluation::query()->create([
            'canonical_prediction_id' => $prediction->id, 'sport_event_id' => $game->sport_event_id,
            'sport_event_result_id' => $result->id, 'sport' => 'nba',
            'evaluation_revision' => 1, 'scoring_version' => 'old-scoring-version',
            'actuals' => ['home_score' => 108, 'away_score' => 100], 'errors' => [],
            'prediction_phase' => 'pregame', 'evaluated_at' => now(),
        ]);
    }
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    handleCanonicalFinalization($game);
    expect(app(CanonicalTeamGameEvaluator::class)->hasPendingEvaluation($game, 'nba'))->toBeFalse()
        ->and(PredictionEvaluation::query()->count())->toBe($oldEvaluation ? 2 : 1)
        ->and(SportEventResult::query()->count())->toBe(1);
})->with([true, false]);

it('ignores a newer draft and evaluates the latest published pregame revision', function () {
    Event::fake([GameFinalized::class]);
    [$game, $published] = canonicalFinalizationFixture();
    CanonicalPrediction::factory()->create([
        'sport' => 'nba', 'sport_event_id' => $game->sport_event_id,
        'revision' => 2, 'phase' => 'pregame', 'publication_state' => 'draft',
    ]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    handleCanonicalFinalization($game);
    expect(PredictionEvaluation::query()->sole()->canonical_prediction_id)->toBe($published->id);
});

it('grades legacy and canonical records independently when both exist for a finalized game', function () {
    [$game, $native] = canonicalFinalizationFixture();
    $before = $native->getAttributes();
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => null]);
    handleCanonicalFinalization($game);
    expect($legacy->fresh()->graded_at)->not->toBeNull()
        ->and($native->fresh()->getAttributes())->toBe($before)
        ->and(PredictionEvaluation::query()->where('canonical_prediction_id', $native->id)->count())->toBe(1)
        ->and(PredictionEvaluation::query()->where('prediction_table', 'nba_predictions')->where('prediction_id', $legacy->id)->count())->toBe(1);
});

it('refuses default-all and multi-sport evaluation history rebuilds without writes', function () {
    [$game] = canonicalFinalizationFixture();
    $this->artisan('sports:rebuild-prediction-evaluations')->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--forecast-source' => 'canonical', '--sport' => ['nba']])->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--forecast-source' => 'canonical', '--sport' => ['nba', 'nfl'], '--game' => [$game->id], '--apply' => true])->assertExitCode(2);
    expect(PredictionEvaluation::count())->toBe(0)->and(SportEventResult::count())->toBe(0);
});

it('supports only explicit bounded canonical games and defaults to a nonpersisting dry run', function (string $sport) {
    [$game, $prediction] = canonicalFinalizationFixture($sport);
    $before = $prediction->getAttributes();
    $args = ['--forecast-source' => 'canonical', '--sport' => [$sport], '--game' => [$game->id], '--season' => $game->season];
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0)->and(SportEventResult::count())->toBe(0);
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--apply' => true])->assertSuccessful();
    expect(PredictionEvaluation::sole()->canonical_prediction_id)->toBe($prediction->id)
        ->and($prediction->fresh()->getAttributes())->toBe($before);
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--apply' => true])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(1)->and(SportEventResult::count())->toBe(1);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb']);

it('preflights the entire explicit canonical scope before writing any evaluation', function () {
    [$game] = canonicalFinalizationFixture();
    $args = ['--forecast-source' => 'canonical', '--sport' => ['nba'], '--game' => [$game->id], '--apply' => true];
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--season' => $game->season + 1])->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--game' => [$game->id, 999999999]])->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--game' => array_fill(0, 101, $game->id)])->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', [...$args, '--forecast-source' => 'invalid'])->assertExitCode(2);
    expect(PredictionEvaluation::count())->toBe(0)->and(SportEventResult::count())->toBe(0);
});

it('retains canonical current-evidence holds and append-only correction history for explicit repair', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    $args = ['--forecast-source' => 'canonical', '--sport' => ['nba'], '--game' => [$game->id], '--apply' => true];
    $game->update(['game_clock' => '1:00']);
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0);
    $game->update(['game_clock' => '0:00']);
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    $first = PredictionEvaluation::sole()->getAttributes();
    $game->update(['home_score' => 109]);
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(2)->and(PredictionEvaluation::orderBy('id')->first()->getAttributes())->toBe($first)
        ->and(BetDecision::count())->toBe(0);
});

afterEach(fn () => Carbon::setTestNow());

function finalizedAuditFixture(string $sport = 'nba'): array
{
    Carbon::setTestNow('2026-11-02 12:00:00');
    [$game, $prediction] = canonicalFinalizationFixture($sport, kickoffAt: CarbonImmutable::instance(now())->subDay()->subHours(4));

    return [$game, $prediction];
}

function finalizedAuditReport(string $sport): array
{
    return app(FinalizedDataCompletenessCheck::class)->run($sport, config('validation.sports.'.$sport));
}

it('audits canonical evaluation revisions without changing approvals or forecast rows', function (string $sport) {
    [$game] = finalizedAuditFixture($sport);
    $report = finalizedAuditReport($sport);
    expect($report['metadata']['grading_source'])->toBe('canonical')->and($report['metadata']['games_missing_grading'])->toBe(1);
    $evaluator = app(CanonicalTeamGameEvaluator::class);
    $evaluator->evaluate($game, $sport);
    $before = [CanonicalPrediction::count(), PredictionEvaluation::count(), SportEventResult::count(), BetDecision::count()];
    expect(finalizedAuditReport($sport)['metadata']['games_missing_grading'])->toBe(0)
        ->and([CanonicalPrediction::count(), PredictionEvaluation::count(), SportEventResult::count(), BetDecision::count()])->toBe($before);
    $game->update(['home_score' => 109]);
    expect(finalizedAuditReport($sport)['metadata']['games_missing_grading'])->toBe(1);
    $evaluator->evaluate($game, $sport);
    expect(finalizedAuditReport($sport)['metadata']['games_missing_grading'])->toBe(0)
        ->and(PredictionEvaluation::count())->toBe(2)->and(SportEventResult::count())->toBe(2);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wnba', 'wcbb']);

it('preserves grading ownership and historical finalized audit behavior under partial switches', function () {
    [$game] = finalizedAuditFixture();
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => null]);
    app(CanonicalTeamGameEvaluator::class)->evaluate($game, 'nba');
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0);
    config(['prediction_lifecycle.canonical_pipeline.nba' => false, 'prediction_lifecycle.canonical_reads.nba' => true]);
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(1);
    $legacy->update(['graded_at' => now()]);
    $before = finalizedAuditReport('nba');
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    expect(finalizedAuditReport('nba'))->toBe($before);
});

it('retains finalization guards and grading grace in the native finalized audit', function (string $guard) {
    [$game] = finalizedAuditFixture();
    if ($guard === 'clock') {
        $game->update(['game_clock' => '1:23']);
    } elseif ($guard === 'age') {
        $game->sportEvent->update(['starts_at' => now()->subMinutes(20)]);
    } else {
        config(['validation.thresholds.finalized_data_completeness.grading_grace_hours' => 48]);
    }
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0);
})->with(['clock', 'age', 'grace']);

it('does not replace missing canonical grading evidence with ungraded legacy rows', function () {
    [$game, $prediction] = finalizedAuditFixture();
    Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => null]);
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0);
});

it('batches canonical grading and removes per-game stats and play existence queries', function () {
    for ($i = 0; $i < 26; $i++) {
        [$game] = finalizedAuditFixture();
        app(CanonicalTeamGameEvaluator::class)->evaluate($game, 'nba');
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_contains($sql, 'nba_predictions')))->toBeEmpty()
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from "prediction_evaluations"')))->toHaveCount(2)
        ->and($queries->filter(fn ($sql) => str_starts_with($sql, 'select exists(') && (str_contains($sql, 'nba_plays') || str_contains($sql, 'nba_player_stats'))))->toBeEmpty();
});

it('keeps obsolete scoring versions pending without overwriting evaluation history', function () {
    [$game, $prediction] = finalizedAuditFixture();
    $result = app(SportEventResultRecorder::class)->record($game->sportEvent, 108, 100, source: 'test', observedAt: now()->toImmutable());
    PredictionEvaluation::create(['canonical_prediction_id' => $prediction->id, 'sport_event_id' => $game->sport_event_id,
        'sport_event_result_id' => $result->id, 'evaluation_revision' => 1, 'sport' => 'nba', 'prediction_phase' => 'pregame',
        'actuals' => [], 'errors' => [], 'market_comparison' => [],
        'scoring_version' => 'obsolete-version', 'evaluation_hash' => str_repeat('b', 64), 'evaluated_at' => now()]);
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(1);
    app(CanonicalTeamGameEvaluator::class)->evaluate($game, 'nba');
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0)
        ->and(PredictionEvaluation::count())->toBe(2);
});

it('does not use an older forecast evaluation to satisfy a newer eligible revision', function () {
    [$game, $prediction] = finalizedAuditFixture();
    app(CanonicalTeamGameEvaluator::class)->evaluate($game, 'nba');
    $next = CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $game->sport_event_id,
        'revision' => $prediction->revision + 1, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(0);
    $next->update(['publication_state' => 'published', 'published_at' => $game->sportEvent->starts_at->subMinute()]);
    expect(finalizedAuditReport('nba')['metadata']['games_missing_grading'])->toBe(1);
});

it('evaluates full-time results through the shared finalization status contract', function (string $sport) {
    Event::fake([GameFinalized::class]);
    [$game, $prediction] = canonicalFinalizationFixture($sport);
    $game->update(['status' => 'STATUS_FULL_TIME']);
    $dispatcher = app(GameFinalizationDispatcher::class);
    $dispatcher->dispatchIfFinalizedTransition($game, 'STATUS_FULL_TIME');
    Event::assertDispatchedTimes(GameFinalized::class, 1);

    handleCanonicalFinalization($game);
    handleCanonicalFinalization($game);
    $dispatcher->dispatchIfFinalizedTransition($game, 'STATUS_FULL_TIME');

    Event::assertDispatchedTimes(GameFinalized::class, 1);
    expect(PredictionEvaluation::query()->sole()->canonical_prediction_id)->toBe($prediction->id)
        ->and(SportEventResult::query()->count())->toBe(1);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wnba', 'wcbb']);

it('does not let delayed grading of a superseded result become the current evaluation', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    $recorder = app(SportEventResultRecorder::class);
    $older = $recorder->record($game->sportEvent, 108, 100, 'espn');
    $corrected = $recorder->record($game->sportEvent, 99, 100, 'espn');
    $evaluator = app(CanonicalPredictionEvaluator::class);
    $current = $evaluator->evaluate($prediction, $corrected);

    expect(fn () => $evaluator->evaluate($prediction, $older))
        ->toThrow(PredictionLifecycleException::class, 'superseded result');
    expect($prediction->fresh()->latestEvaluation->id)->toBe($current->id)
        ->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and(SportEventResult::query()->count())->toBe(2);
});

it('rechecks publication admission instead of trusting a worker cached prediction', function () {
    [$game, $cached] = canonicalFinalizationFixture();
    $result = app(SportEventResultRecorder::class)->record($game->sportEvent, 108, 100, 'espn');
    $cached->fresh()->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect(fn () => app(CanonicalPredictionEvaluator::class)->evaluate($cached, $result))
        ->toThrow(PredictionLifecycleException::class, 'Only published');
    expect(PredictionEvaluation::query()->count())->toBe(0);
});

it('withholds a prior grade while a corrected result is awaiting evaluation', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    handleCanonicalFinalization($game);
    $prior = $prediction->fresh()->latestEvaluation;
    app(SportEventResultRecorder::class)->record($game->sportEvent, 99, 100, 'espn');

    expect($prediction->fresh()->latestEvaluation)->toBeNull()
        ->and(CanonicalPrediction::query()->whereKey($prediction->id)->whereHas('latestEvaluation')->exists())->toBeFalse()
        ->and($prior->fresh()->actuals['home_score'])->toBe(108);
    $game->update(['home_score' => 99]);
    handleCanonicalFinalization($game);
    $reloaded = CanonicalPrediction::query()->with('latestEvaluation')->findOrFail($prediction->id);
    expect($reloaded->latestEvaluation->actuals['home_score'])->toBe(99)
        ->and(CanonicalPrediction::query()->whereKey($prediction->id)->whereHas('latestEvaluation')->exists())->toBeTrue()
        ->and(PredictionEvaluation::query()->count())->toBe(2);
});

it('refuses an older observed score from reverting a newer recorded correction', function () {
    [$game] = canonicalFinalizationFixture();
    $recorder = app(SportEventResultRecorder::class);
    $observed = CarbonImmutable::now()->startOfSecond();
    $recorder->record($game->sportEvent, 108, 100, 'espn', observedAt: $observed);
    $current = $recorder->record($game->sportEvent, 99, 100, 'espn', observedAt: $observed->addMinute());

    expect(fn () => $recorder->record($game->sportEvent, 108, 100, 'espn', observedAt: $observed))
        ->toThrow(PredictionLifecycleException::class, 'older observation');
    expect(SportEventResult::query()->count())->toBe(2)
        ->and(SportEventResult::query()->orderByDesc('revision')->first()->id)->toBe($current->id);
});

it('omits a grade invalidated between the performance query and eager loading', function () {
    [$game] = canonicalFinalizationFixture('nba', withRun: true);
    handleCanonicalFinalization($game);
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $dispatcher = clone $original;
    $connection->setEventDispatcher($dispatcher);
    $corrected = false;
    $dispatcher->listen(QueryExecuted::class, function ($query) use (&$corrected, $game) {
        if (! $corrected && str_starts_with(str_replace('`', '"', $query->sql), 'select "predictions".*')
            && str_contains($query->sql, 'prediction_evaluations')) {
            $corrected = true;
            app(SportEventResultRecorder::class)->record($game->sportEvent, 99, 100, 'espn');
        }
    });
    try {
        $stats = app(PerformanceStatistics::class)->getOverallStats();
        expect($corrected)->toBeTrue()->and($stats['total_predictions'])->toBe(0);
    } finally {
        $connection->setEventDispatcher($original);
    }
});

it('holds new CFB grading on unknown current kickoff while preserving all recorded evaluation history', function () {
    [$game, $prediction] = canonicalFinalizationFixture('cfb');
    $service = app(CanonicalTeamGameEvaluator::class);
    $first = $service->evaluate($game->fresh(), 'cfb');
    expect($first)->not->toBeNull();
    $history = PredictionEvaluation::orderBy('id')->get()->map->getRawOriginal()->all();
    $results = SportEventResult::orderBy('id')->get()->map->getRawOriginal()->all();
    $inputs = $prediction->calculationRun->inputSnapshot->getRawOriginal();
    $cachedEvent = $game->fresh()->sportEvent;
    $source = $game->kickoff_observation['raw_date'];
    app(SportEventIdentitySynchronizer::class)->mutateAndSync('cfb', $game, function ($current) use ($source): void {
        $current->forceFill(['kickoff_observation' => CfbGameStart::observation($source, ['timeValid' => false], 'espn_scoreboard', (string) $current->espn_event_id)])->saveQuietly();
    });
    expect(CanonicalEventStart::forEvent($cachedEvent))->not->toBeNull()
        ->and(CanonicalEventStart::forCurrentCfbEvent($cachedEvent))->toBeNull()
        ->and($service->evaluate($game->fresh(), 'cfb'))->toBeNull()
        ->and(PredictionEvaluation::orderBy('id')->get()->map->getRawOriginal()->all())->toBe($history)
        ->and(SportEventResult::orderBy('id')->get()->map->getRawOriginal()->all())->toBe($results)
        ->and($prediction->calculationRun->inputSnapshot->fresh()->getRawOriginal())->toBe($inputs);
    app(SportEventIdentitySynchronizer::class)->mutateAndSync('cfb', $game, function ($current) use ($source): void {
        $current->fill(CfbGameStart::attributes($source, [], 'espn_scoreboard', (string) $current->espn_event_id))->saveQuietly();
    });
    expect($service->evaluate($game->fresh(), 'cfb')?->id)->toBe($first->id)
        ->and(PredictionEvaluation::orderBy('id')->get()->map->getRawOriginal()->all())->toBe($history);
});
