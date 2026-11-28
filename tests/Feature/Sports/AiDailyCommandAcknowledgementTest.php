<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-09-08 12:00:00');
    config(['nfl.season.default' => 2026, 'prediction_lifecycle.canonical_pipeline.nfl' => false,
        'prediction_lifecycle.canonical_reads.nfl' => false, 'prediction_review.enabled' => false,
        'ai.features.daily_prediction_analysis.enabled' => true]);
});

afterEach(fn () => $this->travelBack());

function aiAcknowledgementPrediction(): Prediction
{
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'season' => 2026,
        'game_date' => '2026-09-08', 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED',
        'short_name' => 'Synthetic away @ home']);

    return Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 3.0,
        'predicted_total' => 43.0, 'win_probability' => 0.6, 'confidence_score' => 62]);
}

function aiAcknowledgementOptions(): array
{
    return ['--sport' => ['nfl'], '--date' => '2026-09-08', '--season' => 2026];
}

function aiAcknowledgementResponse(): array
{
    return ['recommendation' => 'moneyline', 'bet_classification' => 'lean', 'ai_confidence' => 60,
        'analysis_confidence' => 60, 'summary' => 'Synthetic analysis.', 'key_factors' => [],
        'risk_flags' => [], 'reason_codes' => [], 'market_notes' => [], 'generated_by' => 'openai:synthetic'];
}

function aiAcknowledgementService($test, ?array $analysis, ?string $failure = null): void
{
    $mock = Mockery::mock(SportsAiContentService::class);
    app()->instance(SportsAiContentService::class, $mock);
    $mock->shouldReceive('providerAvailabilityMessage')->once()->andReturnNull();
    $mock->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturn($analysis);
    $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturn($failure);
    foreach (['generateDataFreshnessAssessment', 'generateMarketReadinessAssessment', 'generateModelAuditAssessment', 'generatePublishingGuardrailAssessment'] as $method) {
        if ($analysis === null) {
            $mock->shouldNotReceive($method);
        } else {
            $mock->shouldReceive($method)->once()->andReturnNull();
        }
    }
}

it('reports failure when a requested analysis is unavailable without rate limiting', function () {
    aiAcknowledgementPrediction();
    aiAcknowledgementService($this, null, 'Synthetic structured response unavailable.');
    $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
    expect(SportsAiPredictionAnalysis::count())->toBe(0)
        ->and(Artisan::output())->not->toContain('  - saved ')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
});

it('does not acknowledge an analysis whose creation is cancelled', function () {
    aiAcknowledgementPrediction();
    aiAcknowledgementService($this, aiAcknowledgementResponse());
    $events = SportsAiPredictionAnalysis::getEventDispatcher();
    SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
    SportsAiPredictionAnalysis::creating(fn () => false);
    try {
        $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
        $output = Artisan::output();
    } finally {
        SportsAiPredictionAnalysis::setEventDispatcher($events);
    }
    expect(SportsAiPredictionAnalysis::count())->toBe(0)
        ->and($output)->not->toContain('  - saved ')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
});

it('does not acknowledge an analysis whose update is cancelled', function () {
    $prediction = aiAcknowledgementPrediction();
    $existing = SportsAiPredictionAnalysis::create(['sport' => 'nfl', 'prediction_id' => $prediction->id,
        'game_id' => $prediction->game_id, 'game_date' => '2026-09-08', 'as_of_date' => '2026-09-08',
        'market' => 'game', 'input_hash' => str_repeat('a', 64), 'raw_payload' => [],
        'recommendation' => 'pass', 'ai_confidence' => 50, 'analysis_confidence' => 50,
        'bet_classification' => 'no_bet', 'summary' => 'Retained original analysis.']);
    $before = DB::table('sports_ai_prediction_analyses')->where('id', $existing->id)->first();
    aiAcknowledgementService($this, aiAcknowledgementResponse());
    $events = SportsAiPredictionAnalysis::getEventDispatcher();
    SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
    SportsAiPredictionAnalysis::updating(fn () => false);
    try {
        $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
        $output = Artisan::output();
    } finally {
        SportsAiPredictionAnalysis::setEventDispatcher($events);
    }
    expect(DB::table('sports_ai_prediction_analyses')->where('id', $existing->id)->first())->toEqual($before)
        ->and($output)->not->toContain('  - saved ')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
});

it('rolls back an observer rewrite of the staged requested analysis', function (string $field) {
    aiAcknowledgementPrediction();
    aiAcknowledgementService($this, aiAcknowledgementResponse());
    $events = SportsAiPredictionAnalysis::getEventDispatcher();
    SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
    SportsAiPredictionAnalysis::saving(function (SportsAiPredictionAnalysis $analysis) use ($field) {
        $analysis->setAttribute($field, $field === 'summary' ? 'Observer replaced the requested summary.' : []);
    });
    try {
        $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
        $output = Artisan::output();
    } finally {
        SportsAiPredictionAnalysis::setEventDispatcher($events);
    }
    expect(SportsAiPredictionAnalysis::count())->toBe(0)
        ->and($output)->not->toContain('  - saved ')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
})->with(['summary', 'metadata']);

it('refuses acknowledgment when a post-create observer removes the actual primary row', function () {
    aiAcknowledgementPrediction();
    aiAcknowledgementService($this, aiAcknowledgementResponse());
    $events = SportsAiPredictionAnalysis::getEventDispatcher();
    SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
    SportsAiPredictionAnalysis::created(fn (SportsAiPredictionAnalysis $analysis) => DB::table($analysis->getTable())->where('id', $analysis->id)->delete());
    try {
        $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
        $output = Artisan::output();
    } finally {
        SportsAiPredictionAnalysis::setEventDispatcher($events);
    }
    expect(SportsAiPredictionAnalysis::count())->toBe(0)
        ->and($output)->not->toContain('  - saved ')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
});

it('retains a persisted partial success but reports another requested unavailable analysis as failure', function () {
    aiAcknowledgementPrediction();
    aiAcknowledgementPrediction();
    $mock = $this->mock(SportsAiContentService::class);
    $mock->shouldReceive('providerAvailabilityMessage')->once()->andReturnNull();
    $mock->shouldReceive('generateDailyPredictionAnalysis')->twice()->andReturn(aiAcknowledgementResponse(), null);
    $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->twice()->andReturn(null, 'Synthetic response unavailable.');
    foreach (['generateDataFreshnessAssessment', 'generateMarketReadinessAssessment', 'generateModelAuditAssessment', 'generatePublishingGuardrailAssessment'] as $method) {
        $mock->shouldReceive($method)->once()->andReturnNull();
    }
    $exit = Artisan::call('sports:ai-daily-predictions', aiAcknowledgementOptions());
    expect(SportsAiPredictionAnalysis::count())->toBe(1)
        ->and(Artisan::output())->toContain('Processed 1; skipped 1')
        ->and($exit)->toBe(1);
    Http::assertNothingSent();
});
