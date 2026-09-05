<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\CalculationReleaseComponent;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\Predictions\PredictionPublisher;
use Carbon\CarbonImmutable;

final class PredictionLifecycleTestSnapshotBuilder implements EventInputSnapshotBuilder
{
    /** @param array<string, mixed> $inputs */
    public function __construct(
        public array $inputs,
        private readonly CarbonImmutable $capturedAt,
        private readonly CarbonImmutable $cutoffAt,
    ) {}

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        return new EventInputSnapshotData(
            schemaVersion: $release->inputSchemaVersion,
            inputs: $this->inputs,
            capturedAt: $this->capturedAt,
            cutoffAt: $this->cutoffAt,
            latestSourceAvailableAt: $this->capturedAt->subMinute(),
            sourceTimestamps: ['team_metrics' => $this->capturedAt->subMinute()->toIso8601String()],
            pregameSafetyStatus: 'verified',
            metadata: ['builder' => self::class],
        );
    }
}

final class PredictionLifecycleTestCalculator implements SportCalculator
{
    public int $calls = 0;

    public bool $shouldFail = false;

    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $this->calls++;

        if ($this->shouldFail) {
            throw new RuntimeException('Intentional calculator failure.');
        }

        $homeProbability = (float) ($snapshot->inputs['home_probability'] ?? 0.61);

        return new PredictionOutput(
            markets: [
                new PredictionMarketOutput('moneyline', 'home', probability: $homeProbability, confidenceScore: 72.5),
                new PredictionMarketOutput('moneyline', 'away', probability: 1 - $homeProbability, confidenceScore: 72.5),
                new PredictionMarketOutput('spread', 'home', projectedLine: -4.5, confidenceScore: 68),
            ],
            metadata: ['reason_codes' => ['HOME_RATING_EDGE']],
            diagnostics: ['calculator' => self::class],
            generatedAt: $this->calls === 1 ? now()->toImmutable() : null,
        );
    }
}

function lifecycleRulesRelease(string $sport = 'nba'): CalculationRelease
{
    $configuration = ['weights' => ['rating' => 0.7, 'market' => 0.3]];

    $release = CalculationRelease::factory()->create([
        'sport' => $sport,
        'configuration' => $configuration,
        'configuration_hash' => app(CanonicalPayloadHasher::class)->hash($configuration),
    ]);

    return app(CalculationReleaseManager::class)->approve(
        $release,
        'prediction-lifecycle-test',
        'Verified rules release.',
        now()->subHour()->toImmutable(),
    );
}

it('approves and selects one frozen rules release without fabricated ML evidence', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $release = lifecycleRulesRelease();

    $selected = app(CalculationReleaseSelector::class)->select($event, 'pregame');

    expect($selected->is($release))->toBeTrue()
        ->and($selected->status)->toBe('approved')
        ->and($selected->components)->toHaveCount(0)
        ->and(fn () => $selected->update(['configuration' => ['changed' => true]]))
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => CalculationReleaseComponent::factory()->create([
        'calculation_release_id' => $selected->getKey(),
    ]))->toThrow(LogicException::class, 'immutable');
});

it('rejects ambiguous or absent approved releases', function () {
    $event = SportEvent::factory()->create(['sport' => 'wnba']);

    expect(fn () => app(CalculationReleaseSelector::class)->select($event, 'pregame'))
        ->toThrow(PredictionLifecycleException::class, 'found 0');

    CalculationRelease::factory()->approved()->count(2)->create(['sport' => 'wnba']);

    expect(fn () => app(CalculationReleaseSelector::class)->select($event, 'pregame'))
        ->toThrow(PredictionLifecycleException::class, 'found 2');
});

it('keeps retired releases selectable for their historical effective window', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $release = lifecycleRulesRelease();
    $retiredAt = now()->subMinutes(10)->toImmutable();

    app(CalculationReleaseManager::class)->retire($release, $retiredAt);

    expect(app(CalculationReleaseSelector::class)->select(
        $event,
        'pregame',
        $retiredAt->subMinute(),
    )->is($release))->toBeTrue()
        ->and(fn () => app(CalculationReleaseSelector::class)->select($event, 'pregame'))
        ->toThrow(PredictionLifecycleException::class, 'found 0');
});

it('creates one immutable snapshot, run, revision, and market set idempotently', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $builder = new PredictionLifecycleTestSnapshotBuilder(
        ['home_probability' => 0.64, 'home_rating' => 1580, 'away_rating' => 1510],
        $capturedAt,
        $event->starts_at,
    );
    $calculator = new PredictionLifecycleTestCalculator;
    $orchestrator = app(PredictionLifecycleOrchestrator::class);

    $first = $orchestrator->generateAndPublish($event, $builder, $calculator);
    $second = $orchestrator->generateAndPublish($event, $builder, $calculator);

    expect($second->is($first))->toBeTrue()
        ->and($calculator->calls)->toBe(1)
        ->and(EventInputSnapshot::query()->count())->toBe(1)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)
        ->and(PredictionMarket::query()->count())->toBe(3)
        ->and($first->publication_state)->toBe('published')
        ->and($first->revision)->toBe(1)
        ->and($first->detail_source)->toBeNull()
        ->and($first->calculationRun->status)->toBe('succeeded')
        ->and($first->output_hash)->toBe($first->calculationRun->output_hash)
        ->and($first->calculationRun->inputSnapshot->content_hash)->toHaveLength(64)
        ->and($first->calculationRun->release->status)->toBe('approved');

    expect(fn () => $first->calculationRun->inputSnapshot->update(['inputs' => ['tampered' => true]]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $first->update(['output_hash' => str_repeat('f', 64)]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $first->markets->first()->update(['probability' => 0.1]))
        ->toThrow(LogicException::class, 'immutable');
});

it('creates a new revision for materially different inputs and supersedes the prior publication', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $builder = new PredictionLifecycleTestSnapshotBuilder(
        ['home_probability' => 0.60],
        $capturedAt,
        $event->starts_at,
    );
    $calculator = new PredictionLifecycleTestCalculator;
    $orchestrator = app(PredictionLifecycleOrchestrator::class);
    $first = $orchestrator->generateAndPublish($event, $builder, $calculator);

    $builder->inputs = ['home_probability' => 0.67];
    $second = $orchestrator->generateAndPublish($event, $builder, $calculator);

    expect($first->fresh()->publication_state)->toBe('superseded')
        ->and($first->fresh()->superseded_at)->not->toBeNull()
        ->and($second->publication_state)->toBe('published')
        ->and($second->revision)->toBe(2)
        ->and($second->supersedes_prediction_id)->toBe($first->getKey())
        ->and($event->predictions()->published()->sole()->is($second))->toBeTrue()
        ->and(EventInputSnapshot::query()->count())->toBe(2)
        ->and(CalculationRun::query()->count())->toBe(2);

    $superseded = $first->fresh();
    $supersededMarket = $superseded->markets()->firstOrFail();

    expect(fn () => $superseded->update(['output_hash' => str_repeat('f', 64)]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $supersededMarket->update(['probability' => 0.1]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $second->update(['publication_state' => 'draft']))
        ->toThrow(LogicException::class, 'Invalid canonical prediction transition');
});

it('rejects publication when a successful draft output was changed', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $builder = new PredictionLifecycleTestSnapshotBuilder(
        ['home_probability' => 0.64],
        $capturedAt,
        $event->starts_at,
    );
    $prediction = app(PredictionLifecycleOrchestrator::class)->generate(
        $event,
        $builder,
        new PredictionLifecycleTestCalculator,
    );

    $prediction->markets()->where('market_type', 'spread')->firstOrFail()->update([
        'projected_line' => -10.5,
    ]);

    expect(fn () => app(PredictionPublisher::class)->publish($prediction))
        ->toThrow(PredictionLifecycleException::class, 'output changed')
        ->and($prediction->fresh()->publication_state)->toBe('draft');
});

it('rejects publishing a pregame draft at or after kickoff', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $prediction = app(PredictionLifecycleOrchestrator::class)->generate(
        $event,
        new PredictionLifecycleTestSnapshotBuilder(
            ['home_probability' => 0.64],
            $capturedAt,
            $event->starts_at,
        ),
        new PredictionLifecycleTestCalculator,
    );

    $this->travelTo($event->starts_at);

    expect(fn () => app(PredictionPublisher::class)->publish($prediction))
        ->toThrow(PredictionLifecycleException::class, 'before the event starts')
        ->and($prediction->fresh()->publication_state)->toBe('draft');
});

it('records failed calculations without creating a prediction', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $builder = new PredictionLifecycleTestSnapshotBuilder(
        ['home_probability' => 0.61],
        $capturedAt,
        $event->starts_at,
    );
    $calculator = new PredictionLifecycleTestCalculator;
    $calculator->shouldFail = true;

    expect(fn () => app(PredictionLifecycleOrchestrator::class)->generate($event, $builder, $calculator))
        ->toThrow(RuntimeException::class, 'Intentional calculator failure');

    $run = CalculationRun::query()->sole();

    expect($run->status)->toBe('failed')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->failure_code)->toBe('RuntimeException')
        ->and(CanonicalPrediction::query()->count())->toBe(0);
});

it('rejects unsafe pregame timing before creating lifecycle records', function () {
    $capturedAt = now()->startOfSecond()->toImmutable();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'starts_at' => $capturedAt->addHour(),
    ]);
    lifecycleRulesRelease();
    $builder = new PredictionLifecycleTestSnapshotBuilder(
        ['home_probability' => 0.61],
        $capturedAt->addHours(2),
        $event->starts_at,
    );

    expect(fn () => app(PredictionLifecycleOrchestrator::class)->generate(
        $event,
        $builder,
        new PredictionLifecycleTestCalculator,
    ))->toThrow(PredictionLifecycleException::class, 'exceeds its prediction cutoff');

    expect(EventInputSnapshot::query()->count())->toBe(0)
        ->and(CalculationRun::query()->count())->toBe(0)
        ->and(CanonicalPrediction::query()->count())->toBe(0);
});
