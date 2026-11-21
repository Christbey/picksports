<?php

use App\Models\AiGeneration;
use App\Services\Predictions\Review\GameAnalysisCoverage;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PredictionReviewFixtures;

it('reports overdue work and unknown reservations without calling providers or changing work', function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'prediction_lifecycle.canonical_reads.nba' => true]);
    [$game] = PredictionReviewFixtures::game('nba');
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', app(GameResearchService::class)->forGame($game, 'nba'));
    $work->update(['deadline_at' => now()->subMinute(), 'state' => 'deferred']);
    AiGeneration::create(['purpose' => 'prediction_review', 'prompt_version' => 'fixture', 'provider' => 'fake', 'model' => 'fake',
        'status' => 'failed', 'input_hash' => hash('sha256', 'fixture'), 'started_at' => now()->subMinute(),
        'metadata' => ['sport' => 'nba', 'reserved_cost_usd' => 0.05]]);
    $report = app(GameAnalysisCoverage::class)->report(['nba']);

    expect($report['sports']['nba']['recorded_work_states'])->toBe(['deferred' => 1])
        ->and($report['sports']['nba']['overdue_unfinished'])->toBe(1)
        ->and($report['usage_last_24_hours']['known_cost_usd'])->toBe(0.0)
        ->and($report['usage_last_24_hours']['unknown_cost_attempts'])->toBe(1)
        ->and($report['usage_last_24_hours']['unknown_reserved_usd'])->toBe(0.05)
        ->and($work->fresh()->state)->toBe('deferred');
    Http::assertNothingSent();
    $this->travelBack();
});

it('rejects unsupported coverage sport filters', function () {
    $this->artisan('sports:report-game-analysis', ['--sport' => ['unknown']])->assertFailed();
});
