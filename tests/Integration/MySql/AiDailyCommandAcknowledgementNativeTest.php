<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    $this->travelTo('2026-09-08 12:00:00');
    config(['nfl.season.default' => 2026, 'prediction_lifecycle.canonical_pipeline.nfl' => false,
        'prediction_lifecycle.canonical_reads.nfl' => false, 'prediction_review.enabled' => false,
        'ai.features.daily_prediction_analysis.enabled' => true]);
});

afterEach(fn () => $this->travelBack());

function aiNativeAcknowledgementPrediction(): Prediction
{
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'season' => 2026,
        'game_date' => '2026-09-08', 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED',
        'short_name' => 'Synthetic away @ home']);

    return Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 3.0,
        'predicted_total' => 43.0, 'win_probability' => 0.6, 'confidence_score' => 62]);
}

function aiNativeAcknowledgementOptions(): array
{
    return ['--sport' => ['nfl'], '--date' => '2026-09-08', '--season' => 2026];
}

function aiNativeAcknowledgementResponse(): array
{
    return ['recommendation' => 'moneyline', 'bet_classification' => 'lean', 'ai_confidence' => 60,
        'analysis_confidence' => 60, 'summary' => 'Synthetic analysis.', 'key_factors' => [],
        'risk_flags' => [], 'reason_codes' => [], 'market_notes' => [], 'generated_by' => 'openai:synthetic'];
}

function aiNativeAcknowledgementService($test, ?array $analysis, ?string $failure = null): void
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

it('persists native JSON and date fields and leaves an unchanged analysis idempotent', function () {
    $prediction = aiNativeAcknowledgementPrediction();
    $mock = $this->mock(SportsAiContentService::class);
    $mock->shouldReceive('providerAvailabilityMessage')->twice()->andReturnNull();
    $mock->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturn(aiNativeAcknowledgementResponse());
    $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturnNull();
    foreach (['generateDataFreshnessAssessment', 'generateMarketReadinessAssessment', 'generateModelAuditAssessment', 'generatePublishingGuardrailAssessment'] as $method) {
        $mock->shouldReceive($method)->once()->andReturnNull();
    }
    expect(Artisan::call('sports:ai-daily-predictions', aiNativeAcknowledgementOptions()))->toBe(0)
        ->and(Artisan::output())->toContain('  - saved ');
    $row = SportsAiPredictionAnalysis::sole();
    $before = DB::table('sports_ai_prediction_analyses')->where('id', $row->id)->first();
    expect($row->sport)->toBe('nfl')->and($row->prediction_id)->toBe($prediction->id)
        ->and($row->canonical_prediction_id)->toBeNull()->and($row->as_of_date->toDateString())->toBe('2026-09-08')
        ->and($row->game_date->toDateString())->toBe('2026-09-08')
        ->and($row->metadata['ai_generated_summary'])->toBe('Synthetic analysis.')
        ->and($row->raw_payload)->toBeArray()->and($row->calculated_edge)->toBeArray();
    expect(Artisan::call('sports:ai-daily-predictions', aiNativeAcknowledgementOptions()))->toBe(0)
        ->and(Artisan::output())->toContain('Processed 0; skipped 1')
        ->and(SportsAiPredictionAnalysis::count())->toBe(1)
        ->and(DB::table('sports_ai_prediction_analyses')->where('id', $row->id)->first())->toEqual($before);
    Http::assertNothingSent();
});

it('reports native creation and update cancellation without a saved acknowledgment or changed row', function () {
    $prediction = aiNativeAcknowledgementPrediction();
    foreach (['creating', 'updating'] as $event) {
        if ($event === 'updating') {
            SportsAiPredictionAnalysis::create(['sport' => 'nfl', 'prediction_id' => $prediction->id,
                'game_id' => $prediction->game_id, 'game_date' => '2026-09-08', 'as_of_date' => '2026-09-08',
                'market' => 'game', 'input_hash' => str_repeat('a', 64), 'raw_payload' => [],
                'recommendation' => 'pass', 'ai_confidence' => 50, 'analysis_confidence' => 50,
                'bet_classification' => 'no_bet', 'summary' => 'Retained original analysis.']);
        }
        $before = DB::table('sports_ai_prediction_analyses')->orderBy('id')->get()->all();
        aiNativeAcknowledgementService($this, aiNativeAcknowledgementResponse());
        $events = SportsAiPredictionAnalysis::getEventDispatcher();
        SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
        SportsAiPredictionAnalysis::{$event}(fn () => false);
        try {
            $exit = Artisan::call('sports:ai-daily-predictions', aiNativeAcknowledgementOptions());
            $output = Artisan::output();
        } finally {
            SportsAiPredictionAnalysis::setEventDispatcher($events);
        }
        expect(DB::table('sports_ai_prediction_analyses')->orderBy('id')->get()->all())->toEqual($before)
            ->and($output)->not->toContain('  - saved ')->and($exit)->toBe(1);
    }
    Http::assertNothingSent();
});

it('rolls back native requested summary and JSON metadata rewrites instead of accepting changed values', function () {
    aiNativeAcknowledgementPrediction();
    foreach (['summary', 'metadata'] as $field) {
        aiNativeAcknowledgementService($this, aiNativeAcknowledgementResponse());
        $events = SportsAiPredictionAnalysis::getEventDispatcher();
        SportsAiPredictionAnalysis::setEventDispatcher(clone $events);
        SportsAiPredictionAnalysis::saving(function (SportsAiPredictionAnalysis $analysis) use ($field) {
            $analysis->setAttribute($field, $field === 'summary' ? 'Observer changed intended summary.' : []);
        });
        try {
            $exit = Artisan::call('sports:ai-daily-predictions', aiNativeAcknowledgementOptions());
            $output = Artisan::output();
        } finally {
            SportsAiPredictionAnalysis::setEventDispatcher($events);
        }
        expect(SportsAiPredictionAnalysis::count())->toBe(0)
            ->and($output)->not->toContain('  - saved ')->and($exit)->toBe(1);
    }
    Http::assertNothingSent();
});
