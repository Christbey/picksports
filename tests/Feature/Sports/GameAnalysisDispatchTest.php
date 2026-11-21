<?php

use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures as Fixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => $this->travelBack());

it('does not dispatch shared analysis with default capability settings', function () {
    Fixtures::game('nba');
    $this->artisan('sports:reconcile-game-analysis', ['--sport' => ['nba']])->assertSuccessful();
    Queue::assertNothingPushed();
});

it('rotates bounded game dispatch and respects dry runs', function () {
    config(['research.enabled' => true, 'research.sports.nba.enabled' => true]);
    [$first] = Fixtures::game('nba');
    [$second] = Fixtures::game('nba');
    $this->artisan('sports:reconcile-game-analysis', ['--sport' => ['nba'], '--limit' => 1, '--dry-run' => true])->assertSuccessful();
    Queue::assertNothingPushed();
    expect(Cache::has('game-analysis-dispatch:nba:'.$first->id))->toBeFalse();
    $this->artisan('sports:reconcile-game-analysis', ['--sport' => ['nba'], '--limit' => 1])->assertSuccessful();
    $this->artisan('sports:reconcile-game-analysis', ['--sport' => ['nba'], '--limit' => 1])->assertSuccessful();
    Queue::assertPushed(RefreshGameAnalysis::class, fn ($job) => $job->gameId === $first->id);
    Queue::assertPushed(RefreshGameAnalysis::class, fn ($job) => $job->gameId === $second->id);
    Http::assertNothingSent();
});

it('does not buy a second legacy review after shared ownership is enabled', function () {
    config(['ai.features.daily_prediction_analysis.enabled' => true,
        'prediction_review.enabled' => true, 'prediction_review.sports.nfl' => true]);
    $content = Mockery::mock(SportsAiContentService::class);
    $content->shouldNotReceive('providerAvailabilityMessage');
    $content->shouldNotReceive('generateDailyPredictionAnalysis');
    app()->instance(SportsAiContentService::class, $content);
    $this->artisan('sports:ai-daily-predictions', ['--sport' => ['nfl']])
        ->expectsOutputToContain('ownership moved')->assertSuccessful();
});

it('does not let a queued automatic job swallow an authorized retry', function () {
    $automatic = new RefreshGameAnalysis('nba', 42);
    $manual = new RefreshGameAnalysis('nba', 42, 7);
    $otherAdmin = new RefreshGameAnalysis('nba', 42, 8);

    expect($automatic->uniqueId())->not->toBe($manual->uniqueId())
        ->and($manual->uniqueId())->not->toBe($otherAdmin->uniqueId())
        ->and((new RefreshGameAnalysis('nba', 42, 7))->uniqueId())->toBe($manual->uniqueId());
});
