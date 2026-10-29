<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\Prediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\SportsGameContextReport;
use App\Services\AI\SportsAiContentService;
use App\Services\Api\V2\SportContextResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use App\Services\Predictions\SportsAiPublishingDecisionPolicy;
use App\Services\Predictions\SportsExternalGameContextBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function canonicalAnalysisFixture(string $sport, ?CalculationRelease $release = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $release ??= CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['recommendation_context' => ['version' => NflRecommendationInputBuilder::VERSION,
            'outputs' => ['predicted_spread' => 4.0, 'predicted_total' => 9.0, 'win_probability' => 0.7], 'model_metadata' => ['source' => 'frozen-canonical']], 'pregame_market' => ['available' => true, 'captured_at' => now()->toIso8601String(),
                'consensus' => ['spread' => ['line' => -2.5], 'total' => ['line' => 8.0]]], 'home' => ['elo' => 1550], 'away' => ['elo' => 1490],
            'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']]]]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

function canonicalAnalysisResponse(): array
{
    return ['recommendation' => 'spread', 'bet_classification' => 'bet', 'ai_confidence' => 90,
        'analysis_confidence' => 80, 'summary' => 'Invented away selection', 'key_factors' => ['Model edge'],
        'risk_flags' => [], 'reason_codes' => [], 'market_notes' => ['spread' => 'Invented away selection'],
        'generated_by' => 'openai:test'];
}

it('uses frozen canonical forecasts and market signs for every sport without legacy queries', function (string $sport) {
    [$game, $prediction, $input] = canonicalAnalysisFixture($sport);
    $legacyClass = app(SportContextResolver::class)->resolve($sport)->models['prediction'];
    $legacy = $legacyClass::query()->create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2', 'home_team_elo' => 1500, 'away_team_elo' => 1500, 'win_probability' => 0.3, 'predicted_spread' => -20, 'predicted_total' => 200]);
    DB::enableQueryLog();
    $payload = app(SportsAiPredictionPayloadBuilder::class)->build($sport, $prediction);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();
    expect($payload['prediction_source']['canonical_prediction_id'])->toBe($prediction->id)
        ->and($payload['prediction_source']['input_content_hash'])->toBe($input->content_hash)
        ->and($payload['calculated_model']['predicted_spread'])->toBe(4.0)
        ->and($payload['calculated_model']['predicted_total'])->toBe(9.0)
        ->and($payload['calculated_edge']['spread_edge'])->toBe(1.5)
        ->and($payload['calculated_edge']['total_edge'])->toBe(1.0)
        ->and($payload['decision_contract']['approved_recommendations'])->toBe([])
        ->and($queries)->not->toContain('"'.$legacy->getTable().'"');
    $this->artisan('sports:ai-daily-predictions', ['--sport' => [$sport], '--season' => 2026,
        '--date' => $game->game_date->toDateString(), '--dry-run' => true])->assertSuccessful();
    expect(SportsAiPredictionAnalysis::count())->toBe(0);
})->with(['nba', 'nfl', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb']);

it('persists native analysis independently of colliding legacy ids and skips unchanged inputs', function () {
    [$game, $prediction] = canonicalAnalysisFixture('nba');
    $legacy = SportsAiPredictionAnalysis::create(['sport' => 'nba', 'prediction_id' => $prediction->id,
        'game_id' => $game->id, 'as_of_date' => now()->toDateString(), 'market' => 'game', 'input_hash' => str_repeat('a', 64),
        'raw_payload' => [], 'recommendation' => 'moneyline', 'ai_confidence' => 50, 'analysis_confidence' => 50,
        'bet_classification' => 'lean', 'summary' => 'Historical legacy analysis']);
    $this->mock(SportsAiContentService::class, function ($mock) {
        $mock->shouldReceive('providerAvailabilityMessage')->twice()->andReturnNull();
        $mock->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturn(canonicalAnalysisResponse());
        $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturnNull();
        foreach (['generateDataFreshnessAssessment', 'generateMarketReadinessAssessment', 'generateModelAuditAssessment', 'generatePublishingGuardrailAssessment'] as $method) {
            $mock->shouldReceive($method)->once()->andReturnNull();
        }
    });
    $options = ['--sport' => ['nba'], '--season' => 2026, '--date' => $game->game_date->toDateString()];
    $before = $prediction->getRawOriginal();
    $this->artisan('sports:ai-daily-predictions', $options)->assertSuccessful();
    $this->artisan('sports:ai-daily-predictions', $options)->assertSuccessful();
    $native = SportsAiPredictionAnalysis::where('canonical_prediction_id', $prediction->id)->sole();
    expect(SportsAiPredictionAnalysis::count())->toBe(2)
        ->and($legacy->fresh()->summary)->toBe('Historical legacy analysis')
        ->and($native->prediction_id)->toBeNull()
        ->and($native->recommendation)->toBe('pass')
        ->and($native->bet_classification)->toBe('watch')
        ->and($native->metadata['publishing_enforcement']['canonical_authority'])->toBe('exact_revision_approved_public_decisions')
        ->and($prediction->fresh()->getRawOriginal())->toBe($before);
    $migration = require database_path('migrations/2026_10_29_121751_add_canonical_prediction_to_sports_ai_prediction_analyses.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve native AI analysis lineage');
});

it('discards analysis when the native revision is withdrawn during generation', function () {
    [$game, $prediction] = canonicalAnalysisFixture('nba');
    $this->mock(SportsAiContentService::class, function ($mock) use ($prediction) {
        $mock->shouldReceive('providerAvailabilityMessage')->once()->andReturnNull();
        $mock->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturnUsing(function () use ($prediction) {
            $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

            return canonicalAnalysisResponse();
        });
        $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturnNull();
        $mock->shouldNotReceive('generateDataFreshnessAssessment');
    });
    $this->artisan('sports:ai-daily-predictions', ['--sport' => ['nba'], '--season' => 2026,
        '--date' => $game->game_date->toDateString()])->assertSuccessful();
    expect(SportsAiPredictionAnalysis::count())->toBe(0);
});

it('caps canonical AI recommendations at approved selections and preserves downgrades', function () {
    $payload = ['prediction_source' => ['type' => 'canonical'], 'decision_contract' => ['approved_recommendations' => [
        ['type' => 'spread', 'recommendation' => 'Home -2.5 at -110', 'bet_decision_id' => 42],
    ]]];
    $policy = app(SportsAiPublishingDecisionPolicy::class);
    $decision = $policy->decide('nba', $payload, canonicalAnalysisResponse(), null);
    expect($decision['summary'])->toContain('Home -2.5 at -110')->not->toContain('Invented away')
        ->and($decision['market_notes']['spread'])->toBe('Home -2.5 at -110')
        ->and($decision['enforcement']['bet_decision_id'])->toBe(42);
    $pass = $policy->decide('nba', $payload, [...canonicalAnalysisResponse(), 'recommendation' => 'pass'], null);
    expect($pass['recommendation'])->toBe('pass')->and($pass['bet_classification'])->toBe('pass')
        ->and($pass['enforcement']['bet_decision_id'])->toBeNull();
    config(['ai.features.publishing_guardrail_review.enforced' => true]);
    $held = $policy->decide('nba', $payload, canonicalAnalysisResponse(), ['decision' => 'hold', 'publishable_classification' => 'watch']);
    expect($held['recommendation'])->toBe('pass')->and($held['bet_classification'])->toBe('pass');
});

it('shares canonical NFL research inputs even when only the read switch is enabled', function () {
    [$game, $prediction] = canonicalAnalysisFixture('nfl');
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false]);
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => -20]);
    $pipeline = app(ResearchPipeline::class);
    DB::enableQueryLog();
    $stored = $pipeline->storedForecast($game);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();
    expect((float) $stored['predicted_spread'])->toBe(4.0)
        ->and($stored['model_metadata'])->toBe(['source' => 'frozen-canonical'])
        ->and($queries)->not->toContain('"nfl_predictions"');
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect($pipeline->storedForecast($game))->toBeNull();
});

it('discards analysis when approval changes during shadow review', function () {
    [$game] = canonicalAnalysisFixture('nba');
    $this->mock(GameBettingRecommendationService::class, function ($mock) {
        $mock->shouldReceive('forPrediction')->twice()->andReturn(
            [['type' => 'spread', 'recommendation' => 'Home -2.5', 'bet_decision_id' => 42]], []
        );
    });
    $this->mock(SportsAiContentService::class, function ($mock) {
        $mock->shouldReceive('providerAvailabilityMessage')->once()->andReturnNull();
        $mock->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturn(canonicalAnalysisResponse());
        $mock->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturnNull();
        foreach (['generateDataFreshnessAssessment', 'generateMarketReadinessAssessment', 'generateModelAuditAssessment', 'generatePublishingGuardrailAssessment'] as $method) {
            $mock->shouldReceive($method)->once()->andReturnNull();
        }
    });
    $this->artisan('sports:ai-daily-predictions', ['--sport' => ['nba'], '--season' => 2026,
        '--date' => $game->game_date->toDateString()])->expectsOutputToContain('canonical inputs or approval changed during review')->assertSuccessful();
    expect(SportsAiPredictionAnalysis::count())->toBe(0);
});

it('keeps native slate limits and never falls back to legacy when native coverage is missing', function () {
    [$game, $prediction] = canonicalAnalysisFixture('nba');
    canonicalAnalysisFixture('nba');
    $options = ['--sport' => ['nba'], '--season' => 2026, '--date' => $game->game_date->toDateString(), '--dry-run' => true];
    $this->artisan('sports:ai-daily-predictions', [...$options, '--limit' => 1])
        ->expectsOutputToContain('Processed 1; skipped 0')->assertSuccessful();
    $this->artisan('sports:ai-daily-predictions', [...$options, '--season' => 2025])
        ->expectsOutputToContain('0 prediction(s)')->assertSuccessful();
    App\Models\NBA\Prediction::factory()->create(['game_id' => $game->id]);
    CanonicalPrediction::query()->where('id', '!=', $prediction->id)->each(fn ($row) => $row->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]));
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->artisan('sports:ai-daily-predictions', $options)->expectsOutputToContain('0 prediction(s)')->assertSuccessful();
    expect(SportsAiPredictionAnalysis::count())->toBe(0);
});

it('builds external research context from native primary markets without mutating forecasts', function () {
    [$game, $prediction] = canonicalAnalysisFixture('nba');
    SportsGameContextReport::create(['sport' => 'nba', 'game_id' => $game->id,
        'status' => 'ready', 'prompt_version' => 'test', 'input_hash' => str_repeat('b', 64),
        'confidence' => 85, 'summary' => 'Stored context', 'researched_at' => now(), 'expires_at' => now()->addHour()]);
    $before = $prediction->getRawOriginal();
    $context = app(SportsExternalGameContextBuilder::class)->build('nba', $game, $prediction);
    expect($context['available'])->toBeTrue()
        ->and($context['context_adjusted_model']['predicted_spread'])->toBe(4.0)
        ->and($context['context_adjusted_model']['predicted_total'])->toBe(9.0)
        ->and($prediction->fresh()->getRawOriginal())->toBe($before);
});
