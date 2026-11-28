<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CalculationRunAttempt;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\Predictions\PredictionPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function recoveryFixture(): array
{
    Queue::fake();
    config(['prediction_lifecycle.calculation_recovery.lease_seconds' => 300,
        'prediction_lifecycle.calculation_recovery.retry_seconds' => 60,
        'prediction_lifecycle.calculation_recovery.max_attempts' => 3,
        'prediction_lifecycle.calculation_recovery.allow_legacy_running_recovery' => false]);
    $event = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => now()->addHours(4)]);
    CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame']);
    $capturedAt = now()->toImmutable();
    $builder = new class($capturedAt) implements EventInputSnapshotBuilder
    {
        public function __construct(private readonly CarbonImmutable $capturedAt) {}

        public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
        {
            return new EventInputSnapshotData(schemaVersion: $release->inputSchemaVersion,
                inputs: ['fixture' => true], capturedAt: $this->capturedAt,
                cutoffAt: $this->capturedAt, latestSourceAvailableAt: $this->capturedAt,
                pregameSafetyStatus: 'verified');
        }
    };

    return [$event, $builder, app(PredictionLifecycleOrchestrator::class)];
}

function recoveryOutput(): PredictionOutput
{
    return new PredictionOutput([new PredictionMarketOutput('moneyline', 'home', probability: 0.61)]);
}

function recoveryCalculator(?Closure $callback = null): SportCalculator
{
    return new class($callback) implements SportCalculator
    {
        public function __construct(private readonly ?Closure $callback) {}

        public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
        {
            return $this->callback === null ? recoveryOutput() : ($this->callback)();
        }
    };
}

it('retries failed identical inputs after cooldown with durable distinct attempt audit', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $failing = recoveryCalculator(fn () => throw new RuntimeException('Transient failure.'));
    expect(fn () => $orchestrator->generate($event, $builder, $failing))->toThrow(RuntimeException::class);
    $first = CalculationRunAttempt::query()->sole();
    $originalAudit = $first->getAttributes();
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
        ->toThrow(PredictionLifecycleException::class, 'retry delay');
    $this->travel(60)->seconds();
    $prediction = $orchestrator->generate($event, $builder, recoveryCalculator());
    $attempts = CalculationRunAttempt::query()->orderBy('attempt_number')->get();
    expect($attempts->pluck('status')->all())->toBe(['failed', 'succeeded'])
        ->and($attempts->pluck('attempt_number')->all())->toBe([1, 2])
        ->and($attempts[0]->owner_token)->not->toBe($attempts[1]->owner_token)
        ->and($attempts[1]->output_hash)->toBe($prediction->output_hash)
        ->and($first->fresh()->getAttributes())->toBe($originalAudit)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(EventInputSnapshot::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1);
});

it('refuses duplicate claims while the first calculator owns an active lease', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $prediction = $orchestrator->generate($event, $builder, recoveryCalculator(function () use ($event, $builder, $orchestrator) {
        expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
            ->toThrow(PredictionLifecycleException::class, 'active attempt lease');

        return recoveryOutput();
    }));
    expect(CalculationRunAttempt::query()->sole()->status)->toBe('succeeded')
        ->and(CanonicalPrediction::query()->sole()->is($prediction))->toBeTrue();
});

it('fences late success and failure after an expired lease is reclaimed', function (bool $lateFailure) {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $calculator = recoveryCalculator(function () use ($event, $builder, $orchestrator, $lateFailure) {
        $this->travel(301)->seconds();
        $orchestrator->generate($event, $builder, recoveryCalculator());
        if ($lateFailure) {
            throw new RuntimeException('Late old worker failure.');
        }

        return recoveryOutput();
    });
    expect(fn () => $orchestrator->generate($event, $builder, $calculator))
        ->toThrow($lateFailure ? RuntimeException::class : PredictionLifecycleException::class,
            $lateFailure ? 'Late old worker failure.' : 'no longer owned');
    expect(CalculationRunAttempt::query()->orderBy('attempt_number')->pluck('status')->all())->toBe(['abandoned', 'succeeded'])
        ->and(CalculationRun::query()->sole()->status)->toBe('succeeded')
        ->and(CalculationRun::query()->sole()->failure_code)->toBeNull()
        ->and(CanonicalPrediction::query()->count())->toBe(1);
})->with([false, true]);

it('refuses output after lease expiry even before another worker reclaims it', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () {
        $this->travel(301)->seconds();

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'lease is expired');
    expect(CanonicalPrediction::query()->count())->toBe(0);
    $orchestrator->generate($event, $builder, recoveryCalculator());
    expect(CalculationRunAttempt::query()->orderBy('attempt_number')->pluck('status')->all())->toBe(['abandoned', 'succeeded']);
});

it('caps failed retries without creating a new run or resetting audit history', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $orchestrator->generate($event, $builder,
            recoveryCalculator(fn () => throw new RuntimeException('Still failing.'))))->toThrow(RuntimeException::class);
        $this->travel(60)->seconds();
    }
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
        ->toThrow(PredictionLifecycleException::class, 'attempt limit');
    expect(CalculationRunAttempt::query()->count())->toBe(3)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(0);
});

it('retains abandoned audit state when the last allowed lease expires', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    config(['prediction_lifecycle.calculation_recovery.max_attempts' => 1]);
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () {
        $this->travel(301)->seconds();

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'lease is expired');
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
        ->toThrow(PredictionLifecycleException::class, 'attempt limit');
    expect(CalculationRunAttempt::query()->sole()->status)->toBe('abandoned')
        ->and(CalculationRun::query()->sole()->status)->toBe('failed');
});

it('rejects new or failed pregame work at kickoff while reusing an existing success', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $prediction = $orchestrator->generate($event, $builder, recoveryCalculator());
    $this->travelTo($event->starts_at);
    expect($orchestrator->generate($event, $builder, recoveryCalculator())->is($prediction))->toBeTrue();
    $other = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => now()]);
    expect(fn () => $orchestrator->generate($other, $builder, recoveryCalculator()))
        ->toThrow(PredictionLifecycleException::class, 'before the event starts');
    expect(CalculationRun::query()->count())->toBe(1);
});

it('fences calculation completion when the event starts or is rescheduled earlier during work', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () use ($event) {
        SportEvent::query()->whereKey($event->id)->update(['starts_at' => now()]);

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'before the event starts');
    expect(CanonicalPrediction::query()->count())->toBe(0)
        ->and(CalculationRunAttempt::query()->sole()->status)->toBe('failed');
});

it('preserves legacy failure evidence when bootstrapping its first audited retry', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder,
        recoveryCalculator(fn () => throw new RuntimeException('Legacy failure.'))))->toThrow(RuntimeException::class);
    // Simulate a pre-migration failed row that has never had an attempt ledger.
    DB::table('calculation_run_attempts')->delete();
    $this->travel(60)->seconds();
    $orchestrator->generate($event, $builder, recoveryCalculator());
    $attempts = CalculationRunAttempt::query()->orderBy('attempt_number')->get();
    expect($attempts->pluck('status')->all())->toBe(['failed', 'succeeded'])
        ->and($attempts[0]->legacy_import)->toBeTrue()
        ->and($attempts[0]->failure_message)->toBe('Legacy failure.')
        ->and($attempts[1]->legacy_import)->toBeFalse();
});

it('makes terminal attempts and their execution identity immutable through Eloquent', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $orchestrator->generate($event, $builder, recoveryCalculator(function () {
        $attempt = CalculationRunAttempt::query()->sole();
        expect(fn () => $attempt->update(['owner_token' => (string) Str::uuid()]))
            ->toThrow(LogicException::class, 'identity and lease');

        return recoveryOutput();
    }));
    $attempt = CalculationRunAttempt::query()->sole();
    expect(fn () => $attempt->update(['status' => 'failed']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $attempt->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

it('locks the shared event before locking a publication candidate', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    $prediction = $orchestrator->generate($event, $builder, recoveryCalculator());
    DB::enableQueryLog();
    app(PredictionPublisher::class)->publish($prediction);
    $queries = collect(DB::getQueryLog())->pluck('query')->values()->all();
    DB::disableQueryLog();
    $eventIndex = collect($queries)->search(fn ($query) => str_contains($query, 'from "sport_events"') || str_contains($query, 'from `sport_events`'));
    $predictionIndices = collect($queries)->filter(fn ($query) => str_contains($query, 'select * from "predictions"') || str_contains($query, 'select * from `predictions`'))->keys();
    expect($eventIndex)->not->toBeFalse()
        ->and($predictionIndices->first())->toBeGreaterThan($eventIndex)
        ->and($prediction->fresh()->publication_state)->toBe('published');
});

it('rejects a failed retry when kickoff has passed without consuming another attempt', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder,
        recoveryCalculator(fn () => throw new RuntimeException('Transient failure.'))))->toThrow(RuntimeException::class);
    $this->travelTo($event->starts_at);
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
        ->toThrow(PredictionLifecycleException::class, 'before the event starts');
    expect(CalculationRunAttempt::query()->count())->toBe(1)
        ->and(CalculationRun::query()->sole()->status)->toBe('failed');
});

it('bootstraps expired legacy execution without allowing its late result to write', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    config(['prediction_lifecycle.calculation_recovery.allow_legacy_running_recovery' => true]);
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () use ($event, $builder, $orchestrator) {
        DB::table('calculation_run_attempts')->delete();
        $this->travel(301)->seconds();
        $orchestrator->generate($event, $builder, recoveryCalculator());

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'no longer owned');
    $attempts = CalculationRunAttempt::query()->orderBy('attempt_number')->get();
    expect($attempts->pluck('status')->all())->toBe(['abandoned', 'succeeded'])
        ->and($attempts[0]->legacy_import)->toBeTrue()
        ->and(CanonicalPrediction::query()->count())->toBe(1);
});

it('refuses legacy running rows without a safe expiry or before their expiry', function (bool $missingStart) {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () use ($event, $builder, $orchestrator, $missingStart) {
        DB::table('calculation_run_attempts')->delete();
        if ($missingStart) {
            CalculationRun::query()->sole()->update(['started_at' => null]);
        }
        expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
            ->toThrow(PredictionLifecycleException::class, 'no safe recovery timestamp');

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'no longer owned');
    expect(CanonicalPrediction::query()->count())->toBe(0)
        ->and(CalculationRunAttempt::query()->count())->toBe(0);
})->with([false, true]);

it('refuses expired unaudited legacy running recovery by default even after its lease age', function () {
    [$event, $builder, $orchestrator] = recoveryFixture();
    expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator(function () use ($event, $builder, $orchestrator) {
        DB::table('calculation_run_attempts')->delete();
        $this->travel(301)->seconds();
        expect(fn () => $orchestrator->generate($event, $builder, recoveryCalculator()))
            ->toThrow(PredictionLifecycleException::class, 'verified drained');

        return recoveryOutput();
    })))->toThrow(PredictionLifecycleException::class, 'no longer owned');
    expect(CalculationRunAttempt::query()->count())->toBe(0)
        ->and(CanonicalPrediction::query()->count())->toBe(0)
        ->and(CalculationRun::query()->sole()->status)->toBe('running');
});
