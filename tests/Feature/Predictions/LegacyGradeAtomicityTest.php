<?php

use App\Events\GameFinalized;
use App\Models\LegacyPredictionGradeRevision;
use App\Models\NBA\Prediction;
use App\Models\PredictionEvaluation;
use App\Services\GameFinalizationDispatcher;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\LegacyPredictionGradeRecorder;
use App\Services\Predictions\PredictionEvaluationRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

function legacyGradeFixture(string $sport, string $status = 'STATUS_FINAL', array $overrides = []): array
{
    $gameClass = 'App\\Models\\'.$sport.'\\Game';
    $predictionClass = 'App\\Models\\'.$sport.'\\Prediction';
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $game = $gameClass::factory()->create([
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'status' => $status, 'season' => 2026, 'home_score' => 84, 'away_score' => 76,
        ...($sport === 'WNBA' ? ['game_date' => now()->subDay()->toDateString(), 'game_time' => '00:00:00', 'game_clock' => '0:00'] : []),
        ...$overrides,
    ]);
    $prediction = $predictionClass::query()->create([
        'game_id' => $game->id, 'predicted_spread' => 5.5, 'predicted_total' => 160,
        'win_probability' => 0.65, 'confidence_score' => 65,
    ]);

    return [$game, $prediction, app('App\\Actions\\'.$sport.'\\GradePredictions')];
}

it('admits both canonical final states across all seven legacy graders', function (string $sport, string $status) {
    [$game, $prediction, $grader] = legacyGradeFixture($sport, $status);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1)
        ->and($prediction->fresh()->graded_at)->not->toBeNull()
        ->and((float) PredictionEvaluation::query()->sole()->actuals['actual_spread'])->toBe(8.0);
})->with(['NBA', 'NFL', 'MLB', 'CBB', 'CFB', 'WNBA', 'WCBB'])->with(['STATUS_FINAL', 'STATUS_FULL_TIME']);

it('rolls back both prediction and evaluation then allows a clean retry after recorder failure', function () {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    app()->instance(PredictionEvaluationRecorder::class, new class extends PredictionEvaluationRecorder
    {
        public function record(Model $prediction, Model $game, string $sport, float $actualSpread, float $actualTotal): void
        {
            parent::record($prediction, $game, $sport, $actualSpread, $actualTotal);
            throw new RuntimeException('injected recorder failure');
        }
    });
    expect(fn () => $grader->executeForGame($game->id))->toThrow(RuntimeException::class, 'injected recorder failure');
    expect($prediction->fresh()->graded_at)->toBeNull()
        ->and($prediction->fresh()->actual_spread)->toBeNull()
        ->and(PredictionEvaluation::query()->count())->toBe(0);
    app()->instance(PredictionEvaluationRecorder::class, new PredictionEvaluationRecorder);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1)
        ->and($grader->executeForGame($game->id)['graded'])->toBe(0)
        ->and(PredictionEvaluation::query()->count())->toBe(1);
});

it('keeps missing score and nonfinal games pending', function (string $sport, array $overrides) {
    [$game, $prediction, $grader] = legacyGradeFixture($sport, 'STATUS_FULL_TIME', $overrides);
    expect($grader->executeForGame($game->id)['graded'])->toBe(0)
        ->and($prediction->fresh()->graded_at)->toBeNull()
        ->and(PredictionEvaluation::query()->count())->toBe(0);
})->with(['NBA', 'NFL', 'MLB', 'CBB', 'CFB', 'WNBA', 'WCBB'])->with([
    'missing home' => [['home_score' => null]], 'missing away' => [['away_score' => null]],
    'not final' => [['status' => 'STATUS_IN_PROGRESS']],
]);

it('preserves WNBA clock and minimum age guards for full time', function (array $overrides) {
    [$game, $prediction, $grader] = legacyGradeFixture('WNBA', 'STATUS_FULL_TIME', $overrides);
    expect($grader->executeForGame($game->id)['graded'])->toBe(0)
        ->and($prediction->fresh()->graded_at)->toBeNull();
})->with([
    'nonfinal clock' => [['game_clock' => '1:00']],
    'too early' => [['game_date' => now('UTC')->toDateString(), 'game_time' => now('UTC')->subMinutes(30)->format('H:i:s')]],
]);

it('rechecks the game after the initial candidate selection', function (bool $stillFinal) {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    $changed = false;
    Prediction::retrieved(function ($row) use ($game, &$changed, $stillFinal): void {
        if (! $changed) {
            $changed = true;
            $game->update(['home_score' => 70, 'away_score' => 80,
                'status' => $stillFinal ? 'STATUS_FULL_TIME' : 'STATUS_IN_PROGRESS']);
        }
    });
    $result = $grader->executeForGame($game->id);
    expect($result['graded'])->toBe($stillFinal ? 1 : 0);
    if ($stillFinal) {
        expect((float) $prediction->fresh()->actual_spread)->toBe(-10.0)
            ->and(PredictionEvaluation::query()->sole()->actuals['home_score'])->toBe(70);
    } else {
        expect($prediction->fresh()->graded_at)->toBeNull()
            ->and(PredictionEvaluation::query()->count())->toBe(0);
    }
})->with([true, false]);

it('converges scoped official corrections without rewriting frozen forecast inputs', function (string $sport) {
    [$game, $prediction, $grader] = legacyGradeFixture($sport);
    $outputs = $prediction->only(['predicted_spread', 'predicted_total', 'win_probability', 'model_metadata']);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1);
    $first = LegacyPredictionGradeRevision::query()->sole();
    $game->update(['home_score' => 70, 'away_score' => 80]);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1)
        ->and((float) $prediction->fresh()->actual_spread)->toBe(-10.0)
        ->and($prediction->fresh()->only(array_keys($outputs)))->toBe($outputs);
    $game->update(['home_score' => 84, 'away_score' => 76]);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1)
        ->and($grader->executeForGame($game->id)['graded'])->toBe(0)
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(3)
        ->and($first->fresh()->grade_snapshot['actual_spread'])->toBe('8.0')
        ->and(PredictionEvaluation::query()->count())->toBe(1);
})->with(['NBA', 'NFL', 'MLB', 'CBB', 'CFB', 'WNBA', 'WCBB']);

it('preserves an explicitly unknown imported baseline and repairs only opted in history', function () {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    $prediction->update(['actual_spread' => 8, 'actual_total' => 160, 'spread_error' => 2.5,
        'total_error' => 0, 'winner_correct' => true, 'graded_at' => now()->subDay()]);
    expect($grader->execute(2026)['graded'])->toBe(0);
    $this->artisan('sports:repair-legacy-prediction-grades', ['sport' => 'nba', '--game' => [$game->id]])->assertSuccessful();
    expect(LegacyPredictionGradeRevision::query()->count())->toBe(0)
        ->and(PredictionEvaluation::query()->count())->toBe(0);
    $this->artisan('sports:repair-legacy-prediction-grades', ['sport' => 'nba', '--game' => [$game->id], '--apply' => true])->assertSuccessful();
    $receipts = LegacyPredictionGradeRevision::query()->orderBy('revision')->get();
    expect($receipts)->toHaveCount(2)
        ->and($receipts[0]->evidence_kind)->toBe('imported_legacy_baseline')
        ->and($receipts[0]->result_hash)->toBeNull()
        ->and($receipts[0]->result_snapshot)->toBeNull()
        ->and($receipts[0]->evaluation_snapshot)->toBeNull()
        ->and($receipts[1]->evidence_kind)->toBe('observed_grade')
        ->and($receipts[1]->supersedes_id)->toBe($receipts[0]->id);
    expect(fn () => $receipts[0]->update(['result_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $receipts[0]->delete())->toThrow(LogicException::class);
});

it('validates the entire repair scope before writing any selected forecast', function () {
    [$game] = legacyGradeFixture('NBA');
    foreach ([[$game->id, 999999], [], range(1, 101), ['1;unsafe']] as $ids) {
        $this->artisan('sports:repair-legacy-prediction-grades', ['sport' => 'nba', '--game' => $ids, '--apply' => true])->assertExitCode(2);
    }
    expect(LegacyPredictionGradeRevision::query()->count())->toBe(0)
        ->and(PredictionEvaluation::query()->count())->toBe(0);
});

it('retains WNBA admission while a prior grade waits for correction', function () {
    [$game, $prediction, $grader] = legacyGradeFixture('WNBA');
    $grader->executeForGame($game->id);
    $game->update(['home_score' => 70, 'game_clock' => '2:00']);
    expect($grader->executeForGame($game->id)['graded'])->toBe(0)
        ->and((float) $prediction->fresh()->actual_spread)->toBe(8.0)
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(1);
});

it('rediscovers a changed already graded legacy forecast after final status', function () {
    [$game, , $grader] = legacyGradeFixture('NBA');
    $grader->executeForGame($game->id);
    $game->update(['home_score' => 70]);
    Event::fake([GameFinalized::class]);
    app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');
    Event::assertDispatched(GameFinalized::class);
});

it('rolls back a correction and its baseline receipts when recording fails', function () {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    $grader->executeForGame($game->id);
    $game->update(['home_score' => 70]);
    app()->instance(PredictionEvaluationRecorder::class, new class extends PredictionEvaluationRecorder
    {
        public function record(Model $prediction, Model $game, string $sport, float $actualSpread, float $actualTotal): void
        {
            parent::record($prediction, $game, $sport, $actualSpread, $actualTotal);
            throw new RuntimeException('failed correction');
        }
    });
    expect(fn () => $grader->executeForGame($game->id))->toThrow(RuntimeException::class, 'failed correction');
    expect((float) $prediction->fresh()->actual_spread)->toBe(8.0)
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(1)
        ->and(PredictionEvaluation::query()->sole()->actuals['home_score'])->toBe(84);
});

it('repairs damaged current projections while preserving the original receipt', function () {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    $grader->executeForGame($game->id);
    $original = LegacyPredictionGradeRevision::query()->sole();
    $prediction->update(['spread_error' => 999]);
    PredictionEvaluation::query()->update(['errors' => ['spread_error' => 999]]);
    expect($grader->executeForGame($game->id)['graded'])->toBe(1)
        ->and((float) $prediction->fresh()->spread_error)->toBe(2.5)
        ->and($original->fresh()->grade_snapshot['spread_error'])->toBe('2.5')
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(2);
});

it('retains a reproducible digest of the stored grading evidence envelope', function () {
    [$game, , $grader] = legacyGradeFixture('NBA');
    $grader->executeForGame($game->id);
    $receipt = LegacyPredictionGradeRevision::query()->sole();
    $evidence = $receipt->attributesToArray();
    unset($evidence['id'], $evidence['receipt_hash'], $evidence['recorded_at'], $evidence['original_graded_at']);
    expect(app(CanonicalPayloadHasher::class)->hash($evidence))->toBe($receipt->receipt_hash);
});

it('protects revision receipts through quiet model APIs as well as eventful writes', function (string $operation) {
    [$game, , $grader] = legacyGradeFixture('NBA');
    $grader->executeForGame($game->id);
    $receipt = LegacyPredictionGradeRevision::query()->sole();
    $originalHash = $receipt->receipt_hash;
    expect(function () use ($receipt, $operation) {
        if ($operation === 'updateQuietly') {
            return $receipt->updateQuietly(['result_hash' => str_repeat('b', 64)]);
        }
        if ($operation === 'saveQuietly') {
            $receipt->result_hash = str_repeat('b', 64);

            return $receipt->saveQuietly();
        }

        if (in_array($operation, ['incrementQuietly', 'decrementQuietly'], true)) {
            return $receipt->{$operation}('revision');
        }

        return $receipt->deleteQuietly();
    })->toThrow(LogicException::class, 'immutable');
    expect($receipt->fresh()->receipt_hash)->toBe($originalHash)
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(1);
})->with(['updateQuietly', 'saveQuietly', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly']);

it('refuses duplicate nullable-version evaluation identities before grading or baseline adoption', function (bool $alreadyGraded) {
    [$game, $prediction, $grader] = legacyGradeFixture('NBA');
    $prediction->update(['model_version' => null, 'feature_version' => null, 'blend_version' => null,
        ...($alreadyGraded ? ['actual_spread' => 1, 'actual_total' => 140, 'graded_at' => now()->subDay()] : [])]);
    foreach ([80, 81] as $homeScore) {
        PredictionEvaluation::query()->create([
            'sport' => 'nba', 'prediction_table' => $prediction->getTable(), 'prediction_id' => $prediction->id,
            'game_id' => $game->id, 'model_version' => null, 'feature_version' => null, 'blend_version' => null,
            'actuals' => ['home_score' => $homeScore, 'away_score' => 70], 'errors' => [], 'evaluated_at' => now(),
        ]);
    }
    $beforePrediction = $prediction->fresh()->getRawOriginal();
    $beforeEvaluations = PredictionEvaluation::query()->orderBy('id')->get()->toArray();
    expect(app(LegacyPredictionGradeRecorder::class)->needsGrading($prediction->fresh(), $game))->toBeTrue();
    expect(fn () => $grader->executeForGame($game->id))->toThrow(LogicException::class, 'Ambiguous legacy evaluation identity');
    $this->artisan('sports:repair-legacy-prediction-grades', ['sport' => 'nba', '--game' => [$game->id], '--apply' => true])
        ->expectsOutputToContain('Ambiguous legacy evaluation identity')->assertFailed();
    expect($prediction->fresh()->getRawOriginal())->toBe($beforePrediction)
        ->and(PredictionEvaluation::query()->orderBy('id')->get()->toArray())->toBe($beforeEvaluations)
        ->and(LegacyPredictionGradeRevision::query()->count())->toBe(0);
})->with([false, true]);
