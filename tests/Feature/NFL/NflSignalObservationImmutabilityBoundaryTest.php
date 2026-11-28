<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NflSignalGrade;
use App\Models\NflSignalObservation;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-10-20 08:00:00');
    Http::preventStrayRequests();
    Notification::fake();
});
afterEach(fn () => $this->travelBack());

function recordedSignalObservationFixture(): array
{
    $game = Game::factory()->create(['season' => 2026, 'week' => 8, 'game_date' => '2026-10-21',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null]);
    $data = ['predicted_spread' => 4.0, 'predicted_total' => 44.0, 'win_probability' => 0.65,
        'model_version' => 'synthetic-signal-observation-v1', 'feature_version' => 'synthetic-features-v1',
        'blend_version' => 'synthetic-blend-v1', 'elo_difference' => 40,
        'model_metadata' => ['analysis_layer' => ['reason_codes' => ['qb_form_home_edge']]]];
    $prediction = Prediction::query()->create(['game_id' => $game->id, ...array_intersect_key($data,
        array_flip(['predicted_spread', 'predicted_total', 'win_probability', 'model_version', 'feature_version', 'blend_version']))]);
    // Capture now through the actual recorder. No caller-supplied safety,
    // training, source certification or reconstructed timestamp markers.
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', $data);
    $result = app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    expect($result['created'])->toBe(1)->and($snapshot->pregame_safe)->toBeTrue();

    return [$game, $snapshot, NflSignalObservation::where('prediction_feature_snapshot_id', $snapshot->id)->sole()];
}

it('rejects every persisted signal observation instance mutation without changing its raw row', function (string $method) {
    [, , $observation] = recordedSignalObservationFixture();
    $before = $observation->fresh()->getRawOriginal();
    $mutate = function () use ($observation, $method) {
        return match ($method) {
            'save', 'saveQuietly', 'saveOrFail' => (function () use ($observation, $method) {
                $observation->label = 'Synthetic changed immutable label';

                return $observation->{$method}();
            })(),
            'update', 'updateQuietly', 'updateOrFail' => $observation->{$method}(['label' => 'Synthetic changed immutable label']),
            'delete', 'deleteQuietly' => $observation->{$method}(),
            'increment', 'incrementQuietly' => $observation->{$method}('week', 1),
            'decrement', 'decrementQuietly' => $observation->{$method}('week', 1),
            'withoutEvents_save' => NflSignalObservation::withoutEvents(function () use ($observation) {
                $observation->label = 'Synthetic changed immutable label';

                return $observation->save();
            }),
            'withoutEvents_update' => NflSignalObservation::withoutEvents(fn () => $observation->update(['label' => 'Synthetic changed immutable label'])),
            'withoutEvents_delete' => NflSignalObservation::withoutEvents(fn () => $observation->delete()),
            'withoutEvents_increment' => NflSignalObservation::withoutEvents(fn () => $observation->increment('week', 1)),
            'withoutEvents_decrement' => NflSignalObservation::withoutEvents(fn () => $observation->decrement('week', 1)),
        };
    };
    $error = null;
    try {
        $mutate();
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $after = $observation->getConnection()->table('nfl_signal_observations')->where('id', $before['id'])->first();
    fwrite(STDERR, json_encode(['method' => $method, 'refused' => $error instanceof LogicException,
        'row_exists' => $after !== null, 'label_changed' => $after !== null && $after->label !== $before['label']]).PHP_EOL);
    expect($error)->toBeInstanceOf(LogicException::class)
        ->and($error?->getMessage())->toBe('NFL signal observations are immutable.')
        ->and($observation->fresh()?->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
    Notification::assertNothingSent();
})->with(['save', 'update', 'delete', 'increment', 'decrement', 'saveOrFail', 'updateOrFail',
    'saveQuietly', 'updateQuietly', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly',
    'withoutEvents_save', 'withoutEvents_update', 'withoutEvents_delete', 'withoutEvents_increment', 'withoutEvents_decrement']);

it('preserves actual recording idempotence reads and ordinary outcome grading without changing observations', function () {
    [$game, $snapshot, $observation] = recordedSignalObservationFixture();
    $before = $observation->fresh()->getRawOriginal();
    expect(app(NflSignalObservationMaterializer::class)->materialize($snapshot))->toMatchArray(['created' => 0, 'existing' => 1, 'signals' => 1]);
    $this->travelTo('2026-10-22 08:00:00');
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $grade = app(NflSignalGradingService::class)->grade($observation);
    expect($grade['created'])->toBeGreaterThan(0)->and($grade['skipped'])->toBeFalse()
        ->and(NflSignalGrade::where('nfl_signal_observation_id', $observation->id)->count())->toBeGreaterThan(0)
        ->and($observation->fresh()->getRawOriginal())->toBe($before)
        ->and($observation->fresh()->featureSnapshot->id)->toBe($snapshot->id);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});
