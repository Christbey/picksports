<?php

use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Jobs\Predictions\DeliverPublicationEffect;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\PredictionPublicationEffect;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\PredictionNarrativeService;
use App\Services\Predictions\PredictionPublisher;
use App\Services\Predictions\PublicationEffectDelivery;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Support\SportsViewCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function publicationEffectFixture(bool $analysis = false, bool $narrative = false): CanonicalPrediction
{
    Queue::fake();
    Http::preventStrayRequests();
    config(['game_analysis.publisher_enabled' => $analysis, 'research.enabled' => $analysis,
        'research.sports.nba.enabled' => $analysis, 'prediction_review.enabled' => false,
        'prediction_lifecycle.canonical_pipeline.nba' => $narrative,
        'prediction_lifecycle.canonical_reads.nba' => $narrative,
        'prediction_lifecycle.publication_effects.max_attempts' => 8,
        'prediction_lifecycle.publication_effects.lease_seconds' => 300,
        'prediction_lifecycle.publication_effects.retry_seconds' => 60]);
    $event = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => now()->addHours(4)]);
    Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba']);
    $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba', 'phase' => 'pregame', 'pregame_safety_status' => 'verified']);
    $output = new PredictionOutput([new PredictionMarketOutput('moneyline', 'home', probability: 0.61)]);
    $hash = app(CanonicalPayloadHasher::class)->hash($output->hashablePayload());
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'output_hash' => $hash, 'trigger' => 'scheduled']);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null, 'output_hash' => $hash, 'output_metadata' => []]);
    foreach ($output->markets as $market) {
        $prediction->markets()->create([...$market->toArray(), 'is_primary' => true]);
    }

    return $prediction;
}

function publicationAnalysisHandler(): void
{
    $research = Mockery::mock(GameResearchService::class);
    $research->shouldReceive('enabled')->with('nba')->andReturn(true);
    $research->shouldReceive('assess')->once()->with(Mockery::type(Game::class), 'nba')->andReturn([]);
    $research->shouldReceive('forGame')->once()->with(Mockery::type(Game::class), 'nba')->andReturn([]);
    app()->instance(GameResearchService::class, $research);
    $reviews = Mockery::mock(PredictionReviewCoordinator::class);
    $reviews->shouldReceive('enabled')->andReturn(false);
    $reviews->shouldReceive('reconcileGame')->once()->with(Mockery::type(Game::class), 'nba', [])->andReturnNull();
    $reviews->shouldNotReceive('retryGame');
    app()->instance(PredictionReviewCoordinator::class, $reviews);
}

it('rolls back publication and all delivery intents if intent persistence fails', function () {
    $prediction = publicationEffectFixture(true, true);
    $delivery = Mockery::mock(PublicationEffectDelivery::class)->makePartial();
    $delivery->shouldReceive('record')->once()->andReturnUsing(function ($prediction): void {
        (new PublicationEffectDelivery)->record($prediction);
        throw new RuntimeException('Injected intent failure.');
    });
    app()->instance(PublicationEffectDelivery::class, $delivery);
    expect(fn () => app(PredictionPublisher::class)->publish($prediction))->toThrow(RuntimeException::class, 'intent failure')
        ->and($prediction->fresh()->publication_state)->toBe('draft')
        ->and(PredictionPublicationEffect::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('recovers a committed publication after its entire wake-up callback was lost', function () {
    $prediction = publicationEffectFixture();
    $cache = app(SportsViewCache::class);
    expect($cache->remember(SportsViewCache::SEGMENT_PREDICTIONS_INDEX, 'fixture', 3600, fn () => 'old'))->toBe('old');
    $delivery = Mockery::mock(PublicationEffectDelivery::class)->makePartial();
    $delivery->shouldReceive('wakeUp')->once()->andReturnNull();
    app()->instance(PublicationEffectDelivery::class, $delivery);
    app(PredictionPublisher::class)->publish($prediction);
    expect($prediction->fresh()->publication_state)->toBe('published')
        ->and(PredictionPublicationEffect::query()->sole()->state)->toBe('pending')
        ->and($cache->remember(SportsViewCache::SEGMENT_PREDICTIONS_INDEX, 'fixture', 3600, fn () => 'new'))->toBe('old');
    app()->instance(PublicationEffectDelivery::class, new PublicationEffectDelivery);
    $this->artisan('sports:reconcile-publication-effects')->assertSuccessful();
    expect(PredictionPublicationEffect::query()->sole()->state)->toBe('completed')
        ->and($cache->remember(SportsViewCache::SEGMENT_PREDICTIONS_INDEX, 'fixture', 3600, fn () => 'new'))->toBe('new');
    app(PredictionPublisher::class)->publish($prediction->fresh());
    expect(PredictionPublicationEffect::query()->count())->toBe(1)
        ->and(PredictionPublicationEffect::query()->sole()->attempts)->toBe(1);
    Http::assertNothingSent();
});

it('keeps committed publication successful when cache invalidation fails and still queues admitted analysis', function () {
    $prediction = publicationEffectFixture(true);
    $cache = Mockery::mock(SportsViewCache::class);
    $cache->shouldReceive('bustSegments')->once()->andThrow(new RuntimeException('Cache unavailable.'));
    app()->instance(SportsViewCache::class, $cache);
    $result = app(PredictionPublisher::class)->publish($prediction);
    $cacheEffect = PredictionPublicationEffect::query()->where('effect', 'cache')->sole();
    $analysis = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    expect($result->publication_state)->toBe('published')
        ->and($cacheEffect->state)->toBe('failed')->and($cacheEffect->attempts)->toBe(1)
        ->and($analysis->state)->toBe('queued');
    Queue::assertPushed(DeliverPublicationEffect::class, fn ($job) => $job->effectId === $analysis->id);
    app()->instance(SportsViewCache::class, new SportsViewCache);
    $this->travel(60)->seconds();
    $this->artisan('sports:reconcile-publication-effects')->assertSuccessful();
    expect($cacheEffect->fresh()->state)->toBe('completed')->and($cacheEffect->fresh()->attempts)->toBe(2)
        ->and($analysis->fresh()->attempts)->toBe(1);
    Http::assertNothingSent();
});

it('retains a failed queue handoff and retries the same intent without changing forecast identity', function () {
    $prediction = publicationEffectFixture(true);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    expect($effect->state)->toBe('failed')->and($effect->reason_code)->toBe('RuntimeException')
        ->and($prediction->fresh()->publication_state)->toBe('published');
    Bus::fake([DeliverPublicationEffect::class]);
    $this->travel(60)->seconds();
    $this->artisan('sports:reconcile-publication-effects')->assertSuccessful();
    Bus::assertDispatched(DeliverPublicationEffect::class, fn ($job) => $job->effectId === $effect->id);
    expect($effect->fresh()->state)->toBe('queued')->and($effect->fresh()->attempts)->toBe(2)
        ->and(CanonicalPrediction::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('fences duplicate and delayed queue copies while reusing the existing non-forced analysis handler', function () {
    $prediction = publicationEffectFixture(true);
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    $oldToken = $effect->owner_token;
    $delivery = app(PublicationEffectDelivery::class);
    expect($delivery->dispatch($effect->id))->toBeFalse();
    $this->travel(301)->seconds();
    expect($delivery->dispatch($effect->id))->toBeTrue();
    $newToken = $effect->fresh()->owner_token;
    expect($newToken)->not->toBe($oldToken);
    $delivery->process($effect->id, $oldToken);
    expect($effect->fresh()->state)->toBe('queued');
    publicationAnalysisHandler();
    $delivery->process($effect->id, $newToken);
    $delivery->process($effect->id, $newToken);
    $delivery->process($effect->id, $oldToken);
    expect($effect->fresh()->state)->toBe('completed')->and($effect->fresh()->attempts)->toBe(2);
    Http::assertNothingSent();
});

it('does not manufacture analysis intents when publisher admission was disabled at publication', function () {
    $prediction = publicationEffectFixture();
    app(PredictionPublisher::class)->publish($prediction);
    config(['game_analysis.publisher_enabled' => true, 'research.enabled' => true, 'research.sports.nba.enabled' => true]);
    app(PredictionPublisher::class)->publish($prediction->fresh());
    $this->artisan('sports:reconcile-publication-effects')->assertSuccessful();
    expect(PredictionPublicationEffect::query()->pluck('effect')->all())->toBe(['cache']);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('cancels an originally admitted analysis when flags, selection or kickoff no longer permit it', function (string $change) {
    $prediction = publicationEffectFixture(true);
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    match ($change) {
        'flag' => config(['game_analysis.publisher_enabled' => false]),
        'selection' => $prediction->fresh()->update(['publication_state' => 'superseded', 'superseded_at' => now()]),
        'kickoff' => $this->travelTo($prediction->sportEvent->starts_at),
    };
    $delivery = app(PublicationEffectDelivery::class);
    if ($change === 'kickoff') {
        $delivery->dispatch($effect->id);
    }
    $delivery->process($effect->id, $effect->fresh()->owner_token);
    expect($effect->fresh()->state)->toBe('cancelled');
    config(['game_analysis.publisher_enabled' => true]);
    expect(app(PublicationEffectDelivery::class)->dispatch($effect->id))->toBeFalse();
    Http::assertNothingSent();
})->with(['flag', 'selection', 'kickoff']);

it('completes deterministic canonical narrative delivery once despite duplicate messages', function () {
    $prediction = publicationEffectFixture(false, true);
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'narrative')->sole();
    $narratives = Mockery::mock(PredictionNarrativeService::class);
    $narratives->shouldReceive('inputHashForSport')->once()->andReturn('fixture-hash');
    $narratives->shouldReceive('forSport')->once()->andReturn(['summary' => 'Deterministic canonical fixture.']);
    app()->instance(PredictionNarrativeService::class, $narratives);
    $delivery = app(PublicationEffectDelivery::class);
    $delivery->process($effect->id, $effect->owner_token);
    $delivery->process($effect->id, $effect->owner_token);
    expect($effect->fresh()->state)->toBe('completed');
    Http::assertNothingSent();
});

it('fences late cache completion or failure after an expired running owner is recovered', function (bool $lateFailure) {
    $prediction = publicationEffectFixture();
    $cache = Mockery::mock(SportsViewCache::class);
    $calls = 0;
    $cache->shouldReceive('bustSegments')->twice()->andReturnUsing(function () use (&$calls, $lateFailure): void {
        $calls++;
        if ($calls === 1) {
            $effect = PredictionPublicationEffect::query()->sole();
            $this->travel(301)->seconds();
            expect(app(PublicationEffectDelivery::class)->dispatch($effect->id))->toBeTrue();
            if ($lateFailure) {
                throw new RuntimeException('Late old cache failure.');
            }
        }
    });
    app()->instance(SportsViewCache::class, $cache);
    app(PredictionPublisher::class)->publish($prediction);
    expect(PredictionPublicationEffect::query()->sole()->state)->toBe('completed')
        ->and(PredictionPublicationEffect::query()->sole()->attempts)->toBe(2)
        ->and(PredictionPublicationEffect::query()->sole()->reason_code)->toBeNull();
})->with([false, true]);

it('caps failed handoffs and keeps an inspectable failed receipt without endless retries', function () {
    $prediction = publicationEffectFixture(true);
    config(['prediction_lifecycle.publication_effects.max_attempts' => 2]);
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('Queue unavailable.'));
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    $this->travel(60)->seconds();
    app(PublicationEffectDelivery::class)->dispatch($effect->id);
    $this->travel(3600)->seconds();
    expect(app(PublicationEffectDelivery::class)->dispatch($effect->id))->toBeFalse()
        ->and($effect->fresh()->state)->toBe('exhausted')->and($effect->fresh()->attempts)->toBe(2)
        ->and($prediction->fresh()->publication_state)->toBe('published');
});

it('reconciliation dry-run is read-only and terminal effect identity cannot be changed', function () {
    $prediction = publicationEffectFixture();
    $delivery = Mockery::mock(PublicationEffectDelivery::class)->makePartial();
    $delivery->shouldReceive('wakeUp')->once()->andReturnNull();
    app()->instance(PublicationEffectDelivery::class, $delivery);
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->sole();
    $before = $effect->getAttributes();
    $this->artisan('sports:reconcile-publication-effects', ['--dry-run' => true])->assertSuccessful();
    expect($effect->fresh()->getAttributes())->toBe($before)
        ->and(fn () => $effect->update(['effect' => 'analysis']))->toThrow(LogicException::class, 'identity');
    Queue::assertNothingPushed();
});

it('keeps the bounded reconcile schedule independent from disabled analysis automation', function () {
    config(['game_analysis.schedule_enabled' => false, 'game_analysis.publisher_enabled' => false]);
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'Sports: Reconcile Publication Effects');
    expect($event !== null)->toBeTrue()
        ->and($event->expression)->toBe('* * * * *')->and($event->filtersPass(app()))->toBeTrue()
        ->and($event->command)->toContain('--limit=100')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue();
});

it('marks a dead final queued or running attempt exhausted after its lease expires', function (string $state) {
    $prediction = publicationEffectFixture(true);
    config(['prediction_lifecycle.publication_effects.max_attempts' => 1]);
    app(PredictionPublisher::class)->publish($prediction);
    $effect = PredictionPublicationEffect::query()->where('effect', 'analysis')->sole();
    if ($state === 'running') {
        $effect->update(['state' => 'running']);
    }
    $this->travel(301)->seconds();
    $this->artisan('sports:reconcile-publication-effects')->assertFailed();
    expect($effect->fresh()->state)->toBe('exhausted')
        ->and($effect->fresh()->reason_code)->toBe('attempt_limit_reached')
        ->and($effect->fresh()->lease_expires_at)->toBeNull()
        ->and($effect->fresh()->completed_at)->not->toBeNull()
        ->and(app(PublicationEffectDelivery::class)->dueQuery()->whereKey($effect->id)->exists())->toBeFalse();
})->with(['queued', 'running']);

it('uses the supported default queue with a bounded job timeout below its worker and reservation leases', function () {
    $job = new DeliverPublicationEffect(1, 'owner');
    expect($job->connection)->toBeNull()
        ->and($job->queue)->toBeNull()
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBeLessThan((int) config('queue.worker_contracts.default.timeout'))
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->timeout)->toBeLessThan((int) config('prediction_lifecycle.publication_effects.lease_seconds'));
});
