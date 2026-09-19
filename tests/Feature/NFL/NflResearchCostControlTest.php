<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\AiGeneration;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Services\AI\AiGenerationRecorder;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\ResearchDeferred;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\NFL\Research\ResearchRefreshPolicy;
use App\Services\NFL\Research\ResearchResponseException;
use App\Services\NFL\Research\ResearchSpendGuard;
use App\Services\Predictions\SportsExternalGameContextBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-09-18 10:00:00');
    Cache::flush();
    Http::preventStrayRequests();
    config(['ai.providers.openai.key' => 'fake-key', 'ai.features.nfl_game_context_research.model' => 'gpt-5.6-luna']);
});

function costControlGame(): Game
{
    return Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => '2026-09-21', 'game_time' => '17:00:00', 'season_type' => '2', 'status' => 'STATUS_SCHEDULED']);
}

function costControlResponse(string $status = 'ready'): array
{
    $url = 'https://example.com/official-injury-report';

    return ['id' => 'resp_cost', 'status' => 'completed', 'model' => 'gpt-5.6-luna',
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        'output' => [
            ['type' => 'web_search_call', 'action' => ['sources' => [['url' => $url]]]],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'status' => $status, 'confidence' => 80, 'summary' => 'Sourced two-sided evidence.',
                'sources' => [['url' => $url, 'source_type' => 'official']],
                'facts' => [['claim' => 'Official injury designations reported.', 'source_urls' => [$url], 'certainty' => 'confirmed']],
                'decision_research' => [
                    'supporting' => [['claim' => 'Sourced support.', 'source_url' => $url]],
                    'opposing' => [['claim' => 'Sourced opposing evidence.', 'source_url' => $url]],
                    'unresolved' => [],
                ],
            ])]]],
        ]];
}

function reserveCostAttempt(Game $game, string $fingerprint = 'unchanged'): AiGeneration
{
    return app(ResearchSpendGuard::class)->reserve($game, $fingerprint, 'openai', 'gpt-5.6-luna',
        fn ($metadata) => app(AiGenerationRecorder::class)->start('nfl_game_context_research', 'test', 'openai', 'gpt-5.6-luna', [], 'nfl_game', (string) $game->id, $metadata));
}

it('reuses the same sourced report for unchanged forecasts and market-only updates', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($game);
    $this->travel(2)->hours();
    $game->odds_updated_at = now();
    $second = $service->research($game);
    expect($first['report']->status)->toBe('ready')
        ->and($second['reused'])->toBeTrue()
        ->and($second['report']->id)->toBe($first['report']->id)
        ->and($second['report']->researched_at->equalTo($first['report']->researched_at))->toBeTrue()
        ->and(AiGeneration::count())->toBe(1);
    Http::assertSentCount(1);
});

it('uses kickoff-aware expiry without extending an existing report', function () {
    $game = costControlGame();
    $policy = app(ResearchRefreshPolicy::class);
    expect($policy->freshnessMinutes($game))->toBe(1440);
    $game->game_date = '2026-09-19';
    expect($policy->freshnessMinutes($game))->toBe(360);
    $game->game_date = '2026-09-18';
    expect($policy->freshnessMinutes($game))->toBe(90);
    $game->game_time = '15:30:00';
    expect($policy->expiresAt($game)->utc()->toDateTimeString())->toBe('2026-09-18 15:30:00');
});

it('ignores reordered evidence and source timestamps but notices changed facts', function () {
    $game = costControlGame();
    $packet = ['documents' => [
        ['id' => 1, 'content_hash' => 'a', 'team' => 'A', 'title' => 'Injury report'],
        ['id' => 2, 'content_hash' => 'b', 'team' => 'B', 'title' => 'Injury report'],
    ], 'availability' => [], 'holds' => []];
    $policy = app(ResearchRefreshPolicy::class);
    $hash = $policy->fingerprint($game, $packet);
    $packet['documents'] = array_reverse($packet['documents']);
    $packet['source_coverage'] = ['checked_at' => now()->addMinute()->toIso8601String()];
    $packet['holds'] = ['stale_or_missing_news_A'];
    expect($policy->fingerprint($game, $packet))->toBe($hash);
    $packet['documents'][0]['content_hash'] = 'new facts';
    expect($policy->fingerprint($game, $packet))->not->toBe($hash);
});

it('throttles repeated partial reports without pretending they are ready', function () {
    Http::fake(['*' => Http::response(costControlResponse('partial'))]);
    $game = costControlGame();
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($game);
    $this->travel(46)->minutes();
    expect(fn () => $service->research($game))->toThrow(ResearchDeferred::class, 'research_retry_not_due')
        ->and($first['report']->fresh()->status)->toBe('partial');
    Http::assertSentCount(1);
    $this->travel(6)->hours();
    $service->research($game);
    Http::assertSentCount(2);
});

it('allows materially changed evidence after the minimum interval', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    $service = app(NflWebContextResearchService::class);
    $service->research($game);
    $game->home_qb_name = 'New Starter';
    expect(fn () => $service->research($game))->toThrow(ResearchDeferred::class, 'research_retry_not_due');
    $this->travel(16)->minutes();
    $service->research($game);
    Http::assertSentCount(2);
});

it('reserves concurrent unknown spend globally and per game before starting a request', function () {
    config(['nfl_research.cost_control.daily_budget_usd' => 0.20]);
    reserveCostAttempt(costControlGame());
    expect(fn () => reserveCostAttempt(costControlGame()))->toThrow(ResearchDeferred::class, 'research_daily_budget_reached')
        ->and(AiGeneration::count())->toBe(1);
    config(['nfl_research.cost_control.daily_budget_usd' => 5, 'nfl_research.cost_control.game_daily_budget_usd' => 0.20]);
    $game = costControlGame();
    reserveCostAttempt($game);
    $this->travel(7)->hours();
    expect(fn () => reserveCostAttempt($game))->toThrow(ResearchDeferred::class, 'research_game_daily_budget_reached');
});

it('keeps unpriced failures reserved and counts them toward the attempt limit', function () {
    config(['nfl_research.cost_control.game_daily_attempts' => 1]);
    $game = costControlGame();
    $attempt = reserveCostAttempt($game);
    $attempt->update(['status' => 'failed']);
    $this->travel(7)->hours();
    expect(fn () => reserveCostAttempt($game))->toThrow(ResearchDeferred::class, 'research_game_daily_budget_reached');
    $this->travel(18)->hours();
    expect(reserveCostAttempt($game)->id)->not->toBe($attempt->id);
});

it('does not let force bypass the spend budget', function () {
    config(['nfl_research.cost_control.daily_budget_usd' => 0]);
    Http::fake();
    expect(fn () => app(NflWebContextResearchService::class)->research(costControlGame(), force: true))
        ->toThrow(ResearchDeferred::class, 'research_daily_budget_reached');
    Http::assertNothingSent();
});

it('rejects unpriced model overrides before creating a paid request', function () {
    Http::fake();
    expect(fn () => app(NflWebContextResearchService::class)->research(costControlGame(), model: 'unpriced-model'))
        ->toThrow(ResearchDeferred::class, 'research_model_pricing_unconfigured');
    Http::assertNothingSent();
});

it('prevents overlapping manual and scheduled research calls', function () {
    $game = costControlGame();
    $lock = Cache::lock('nfl-paid-research-game:'.$game->id, 300);
    $lock->get();
    try {
        expect(fn () => app(NflWebContextResearchService::class)->research($game))
            ->toThrow(ResearchDeferred::class, 'research_game_already_running');
    } finally {
        $lock->release();
    }
});

it('records usage for paid invalid or incomplete responses without saving a report', function (string $failure) {
    $response = costControlResponse();
    if ($failure === 'incomplete') {
        $response['status'] = 'incomplete';
    } else {
        $response['output'][1]['content'][0]['text'] = '{bad json';
    }
    Http::fake(['*' => Http::response($response)]);
    expect(fn () => app(NflWebContextResearchService::class)->research(costControlGame()))->toThrow(ResearchResponseException::class);
    $generation = AiGeneration::firstOrFail();
    expect($generation->status)->toBe('failed')
        ->and($generation->cost_usd)->toBe('0.010800')
        ->and($generation->input_tokens)->toBe(1000)
        ->and($generation->metadata['provider_response_id'])->toBe('resp_cost')
        ->and(SportsGameContextReport::count())->toBe(0);
})->with(['incomplete', 'invalid_json']);

it('does not turn missing provider usage into a zero-cost completed call', function () {
    $response = costControlResponse();
    unset($response['usage']);
    Http::fake(['*' => Http::response($response)]);
    app(NflWebContextResearchService::class)->research(costControlGame());
    expect(AiGeneration::first()->cost_usd)->toBeNull();
});

it('withholds changed evidence from the prediction payload even before its old expiry', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    app(NflWebContextResearchService::class)->research($game);
    $game->home_qb_name = 'New Starter';
    $context = app(SportsExternalGameContextBuilder::class)->build('nfl', $game);
    expect($context['available'])->toBeFalse()
        ->and($context['reason'])->toBe('research_evidence_changed_or_stale');
});

it('keeps a budget-deferred research revision on hold and exposes why', function () {
    config(['nfl_research.cost_control.daily_budget_usd' => 0]);
    $game = costControlGame();
    Prediction::factory()->create(['game_id' => $game->id]);
    $this->mock(GeneratePredictionFromHistoricalElo::class, fn ($m) => $m->shouldReceive('preview')->andReturn([
        'outputs' => ['predicted_spread' => 5, 'predicted_total' => 42, 'win_probability' => .6],
        'model_metadata' => [], 'model_version' => 'cost-test',
    ]));
    $this->mock(PlayerPropAnalyzer::class, fn ($m) => $m->shouldReceive('previewNflGame')->andReturn([['prop_id' => 1, 'status' => 'candidate']]));
    $revision = app(ResearchPipeline::class)->review($game);
    expect($revision->brief['eligibility']['status'])->toBe('hold')
        ->and($revision->brief['research_refresh']['deferred_reason'])->toBe('research_daily_budget_reached')
        ->and($revision->brief['eligibility']['data_reasons'])->toContain('research_incomplete_or_stale')
        ->and($revision->brief['player_props'][0]['status'])->toBe('hold')
        ->and($revision->brief['market_holds']['props'])->toContain('research_daily_budget_reached')
        ->and(AiGeneration::count())->toBe(0);
});

it('keeps a changed candidate on hold during an explicit no-web review', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 2]);
    app(NflWebContextResearchService::class)->research($game);
    $this->mock(GeneratePredictionFromHistoricalElo::class, fn ($m) => $m->shouldReceive('preview')->andReturn([
        'outputs' => ['predicted_spread' => 8, 'predicted_total' => 42, 'win_probability' => .7],
        'model_metadata' => [], 'model_version' => 'changed-test',
    ]));
    $this->mock(PlayerPropAnalyzer::class, fn ($m) => $m->shouldReceive('previewNflGame')->andReturn([]));
    $revision = app(ResearchPipeline::class)->review($game, research: false);
    expect($revision->revised['predicted_spread'])->toBe(8)
        ->and($revision->brief['research_refresh']['evidence_current'])->toBeTrue()
        ->and($revision->brief['eligibility']['data_reasons'])->toContain('research_candidate_changed');
    Http::assertSentCount(1);
});

it('stores the full candidate hash before prompt compaction and reuses it consistently', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    $candidate = ['predicted_spread' => 5, 'predicted_total' => 42, 'win_probability' => .6,
        'model_metadata' => ['analysis_layer' => ['raw_bet_classification' => 'no_bet_risk', 'bet_classification' => 'hold']]];
    $game->setAttribute('research_candidate', $candidate);
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($game);
    expect($first['report']->raw_payload['candidate_hash'])->toBe(app(ResearchPipeline::class)->candidateContextHash($candidate));
    expect($service->research($game)['reused'])->toBeTrue();
    Http::assertSentCount(1);
});

it('revalidates a material forecast change after the minimum interval without bypassing budgets', function () {
    Http::fake(['*' => Http::response(costControlResponse())]);
    $game = costControlGame();
    $game->setAttribute('research_candidate', ['predicted_spread' => 2, 'predicted_total' => 42]);
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($game);
    $game->setAttribute('research_candidate', ['predicted_spread' => 8, 'predicted_total' => 42]);
    expect(fn () => $service->research($game))->toThrow(ResearchDeferred::class, 'research_retry_not_due');
    $this->travel(16)->minutes();
    $second = $service->research($game);
    expect($second['report']->id)->not->toBe($first['report']->id)
        ->and($second['report']->raw_payload['candidate_hash'])->toBe(app(ResearchPipeline::class)->candidateContextHash($game->getAttribute('research_candidate')));
    Http::assertSentCount(2);
    config(['nfl_research.cost_control.daily_budget_usd' => 0]);
    $game->setAttribute('research_candidate', ['predicted_spread' => 12]);
    $this->travel(16)->minutes();
    expect(fn () => $service->research($game))->toThrow(ResearchDeferred::class, 'research_daily_budget_reached');
    Http::assertSentCount(2);
});

it('persists a new explicit hold instead of losing the entire revision on provider failure', function () {
    $game = costControlGame();
    Prediction::factory()->create(['game_id' => $game->id]);
    $this->mock(GeneratePredictionFromHistoricalElo::class, fn ($m) => $m->shouldReceive('preview')->andReturn([
        'outputs' => ['predicted_spread' => 5, 'predicted_total' => 42, 'win_probability' => .6],
        'model_metadata' => [], 'model_version' => 'failure-test',
    ]));
    $this->mock(PlayerPropAnalyzer::class, fn ($m) => $m->shouldReceive('previewNflGame')->andReturn([]));
    $this->mock(NflWebContextResearchService::class, fn ($m) => $m->shouldReceive('research')->andThrow(new ConnectionException('Timed out')));
    $revision = app(ResearchPipeline::class)->review($game);
    expect($revision->exists)->toBeTrue()
        ->and($revision->brief['eligibility']['status'])->toBe('hold')
        ->and($revision->brief['eligibility']['data_reasons'])->toContain('research_refresh_failed');
});

it('keeps timeout spend unknown and prevents immediately purchasing the same request again', function () {
    Http::fake(fn () => throw new ConnectionException('Timed out'));
    $game = costControlGame();
    expect(fn () => app(NflWebContextResearchService::class)->research($game))->toThrow(ConnectionException::class);
    $row = AiGeneration::firstOrFail();
    expect($row->cost_usd)->toBeNull()->and(app(ResearchSpendGuard::class)->accountedCost($row))->toBe(0.15);
    expect(fn () => app(NflWebContextResearchService::class)->research($game))->toThrow(ResearchDeferred::class, 'research_retry_not_due');
    expect(AiGeneration::count())->toBe(1);
});

it('reports measured and unknown reserved costs separately without calling a provider', function () {
    $first = reserveCostAttempt(costControlGame());
    $first->update(['status' => 'completed', 'cost_usd' => 0.05]);
    reserveCostAttempt(costControlGame());
    expect(Artisan::call('nfl:research-costs', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['totals']['recorded_estimate_usd'])->toEqual(0.05)
        ->and($report['totals']['unpriced_attempts'])->toBe(1)
        ->and($report['totals']['budget_accounted_usd'])->toEqual(0.20);
    $this->artisan('nfl:research-costs', ['--hours' => 0])->assertFailed();
});
