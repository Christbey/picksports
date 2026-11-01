<?php

use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Services\GameFinalizationDispatcher;
use App\Services\Predictions\CanonicalTeamGameEvaluator;
use App\Services\Predictions\SportEventResultRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function canonicalFinalizationFixture(string $sport = 'nba'): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true,
        "prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $start = now()->subHours(4);
    $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => $start]);
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $game = $gameClass::factory()->create([
        'home_team_id' => $teamClass::factory()->create()->id,
        'away_team_id' => $teamClass::factory()->create()->id,
        'sport_event_id' => $event->id, 'status' => 'STATUS_FINAL',
        'home_score' => 108, 'away_score' => 100,
        ...($sport !== 'mlb' ? ['game_clock' => '0:00'] : []),
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id,
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
    $prediction->update(['publication_state' => $state]);
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

it('rebuilds canonical evaluations without legacy predictions for every supported sport', function (string $sport) {
    [$game, $prediction] = canonicalFinalizationFixture($sport);
    $before = $prediction->getAttributes();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => [$sport], '--season' => $game->season])
            ->expectsOutputToContain('Rebuilt 1 prediction evaluation rows.')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(PredictionEvaluation::sole()->canonical_prediction_id)->toBe($prediction->id)
        ->and($queries->implode(' '))->not->toContain($sport.'_predictions')
        ->and($prediction->fresh()->getAttributes())->toBe($before);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => [$sport]])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(1)->and(SportEventResult::count())->toBe(1);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb']);

it('keeps public read switches independent from evaluation rebuild ownership', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    config(['prediction_lifecycle.canonical_pipeline.nba' => false, 'prediction_lifecycle.canonical_reads.nba' => true]);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['nba']])->expectsOutputToContain('Rebuilt 0 prediction evaluation rows.')->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--forecast-source' => 'canonical', '--sport' => ['nba']])->assertSuccessful();
    expect(PredictionEvaluation::sole()->canonical_prediction_id)->toBe($prediction->id);
});

it('preserves explicit historical rebuilds without modifying native evaluations', function () {
    [$game] = canonicalFinalizationFixture();
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => now(), 'spread_error' => 1.0, 'total_error' => 2.0]);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--forecast-source' => 'legacy', '--sport' => ['nba']])->assertSuccessful();
    expect(PredictionEvaluation::sole()->prediction_table)->toBe('nba_predictions')
        ->and(PredictionEvaluation::sole()->prediction_id)->toBe($legacy->id)
        ->and(PredictionEvaluation::sole()->canonical_prediction_id)->toBeNull();
});

it('keeps final-clock and age holds and applies a shared successful-evaluation limit', function () {
    [$tooYoung] = canonicalFinalizationFixture();
    $tooYoung->sportEvent->update(['starts_at' => now()->subMinutes(20)]);
    [$badClock] = canonicalFinalizationFixture();
    $badClock->update(['game_clock' => '1:00']);
    [, $eligible] = canonicalFinalizationFixture();
    canonicalFinalizationFixture('nfl');
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['nba', 'nfl'], '--limit' => 1])->assertSuccessful();
    expect(PredictionEvaluation::sole()->canonical_prediction_id)->toBe($eligible->id);
});

it('keeps rebuild season filters and reports missing native forecasts without fallback', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['nba'], '--season' => $game->season + 1])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0);
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['nba']])->expectsOutputToContain('Canonical evaluations: 0; skipped final games: 1.')->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--forecast-source' => 'invalid', '--sport' => ['nba']])->assertExitCode(2);
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['invalid']])->assertExitCode(2);
});

it('appends corrected evaluations during recovery without changing prior results or approvals', function () {
    [$game, $prediction] = canonicalFinalizationFixture();
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    $args = ['--sport' => ['nba']];
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    $first = PredictionEvaluation::sole()->getAttributes();
    $game->update(['home_score' => 109]);
    $this->artisan('sports:rebuild-prediction-evaluations', $args)->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(2)->and(SportEventResult::count())->toBe(2)
        ->and(PredictionEvaluation::orderBy('id')->first()->getAttributes())->toBe($first)
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses)
        ->and(BetDecision::count())->toBe(0);
});

it('shares the rebuild limit across historical and canonical ownership', function () {
    [$nba] = canonicalFinalizationFixture();
    config(['prediction_lifecycle.canonical_pipeline.nba' => false]);
    Prediction::factory()->create(['game_id' => $nba->id, 'graded_at' => now()]);
    canonicalFinalizationFixture('nfl');
    $this->artisan('sports:rebuild-prediction-evaluations', ['--sport' => ['nba', 'nfl'], '--limit' => 1])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(1)->and(PredictionEvaluation::sole()->prediction_table)->toBe('nba_predictions');
});
