<?php

use App\AI\Agents\NflGameContextResearchAgent;
use App\Models\AiGeneration;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\AI\AiGenerationRecorder;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\ResearchDeferred;
use App\Services\NFL\Research\ResearchSpendGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-09-29 10:00:00');
    Cache::flush();
    Http::preventStrayRequests();
    config(['nfl_research.web_search_enabled' => false, 'ai.providers.openai.key' => 'fake-key']);
    $this->game = Game::factory()->create([
        'home_team_id' => Team::factory()->create(['abbreviation' => 'BUF'])->id,
        'away_team_id' => Team::factory()->create(['abbreviation' => 'MIA'])->id,
        'game_date' => '2026-10-04', 'game_time' => '17:00:00',
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED',
    ]);
    $this->packet = ['documents' => collect(['BUF', 'MIA'])->map(fn ($team, $i) => [
        'id' => $i + 1, 'team' => $team, 'url' => 'https://example.com/'.$team,
        'title' => 'Official practice report', 'text' => 'Current official practice participation and injury designations.',
        'content_hash' => 'evidence-'.$team, 'observed_at' => now()->toIso8601String(),
    ])->all(), 'availability' => [], 'holds' => [], 'source_coverage' => []];
    $this->partialMock(EvidencePacket::class, function ($mock) {
        $mock->shouldReceive('forGame')->andReturnUsing(fn () => $this->packet);
    });
});

function suppliedSourceResponse(string $url = 'https://example.com/BUF'): array
{
    return ['id' => 'resp_sources', 'status' => 'completed', 'model' => 'gpt-5.6-luna',
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'status' => 'ready', 'confidence' => 80, 'summary' => 'Analysis of supplied evidence.',
            'sources' => [['url' => $url, 'source_type' => 'official']],
            'facts' => [['claim' => 'Practice participation was reported.', 'source_urls' => [$url], 'certainty' => 'confirmed']],
            'decision_research' => [
                'supporting' => [['claim' => 'Evidence supporting the forecast.', 'source_url' => $url]],
                'opposing' => [['claim' => 'Evidence against the forecast.', 'source_url' => $url]],
                'unresolved' => [],
            ],
        ])]]]]];
}

it('analyzes supplied documents without search tools and reuses unchanged evidence', function () {
    Http::fake(['*' => Http::response(suppliedSourceResponse())]);
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($this->game);
    $this->travel(1)->hours();
    $second = $service->research($this->game);
    expect($first['report']->status)->toBe('ready')
        ->and($first['payload']['sources'][0]['ingested_document'])->toBeTrue()
        ->and($first['payload']['sources'][0]['provider_citation'])->toBeFalse()
        ->and($second['reused'])->toBeTrue()
        ->and($second['report']->id)->toBe($first['report']->id)
        ->and(AiGeneration::first()->metadata['search_cap'])->toBe(0)
        ->and(AiGeneration::first()->metadata['web_search_calls'])->toBe(0)
        ->and(app(NflGameContextResearchAgent::class)->tools())->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => ! isset($request->data()['tools'])
        && ! isset($request->data()['max_tool_calls'])
        && str_contains($request['input'], 'https://example.com/BUF')
        && str_contains($request['input'], 'https://example.com/MIA'));
});

it('does not spend when a team has no evidence or collection is stale', function (string $gap) {
    if ($gap === 'missing') {
        array_pop($this->packet['documents']);
    } else {
        $this->packet['holds'] = ['stale_or_missing_news_BUF'];
    }
    expect(fn () => app(NflWebContextResearchService::class)->research($this->game, force: true))
        ->toThrow(ResearchDeferred::class, 'research_source_evidence_not_ready');
    expect(AiGeneration::count())->toBe(0);
    Http::assertNothingSent();
})->with(['missing', 'stale']);

it('rejects unsupplied citations even when the provider returns annotations and citation checking is disabled', function () {
    config(['ai.features.nfl_game_context_research.require_provider_citations' => false]);
    $response = suppliedSourceResponse('https://invented.example/news');
    $response['output'][0]['content'][0]['annotations'] = [['url' => 'https://invented.example/news']];
    Http::fake(['*' => Http::response($response)]);
    $result = app(NflWebContextResearchService::class)->research($this->game);
    expect($result['report']->status)->toBe('insufficient')->and($result['payload']['sources'])->toBe([]);
});

it('reanalyzes changed evidence only after the source-analysis interval', function () {
    Http::fake(['*' => Http::response(suppliedSourceResponse())]);
    $service = app(NflWebContextResearchService::class);
    $first = $service->research($this->game);
    $this->packet['documents'][0]['content_hash'] = 'updated-injury-evidence';
    $this->packet['documents'][0]['text'] = 'An updated official injury designation supersedes the earlier report.';
    $this->travel(1)->hours();
    expect(fn () => $service->research($this->game))->toThrow(ResearchDeferred::class, 'research_retry_not_due');
    Http::assertSentCount(1);
    $this->travel(5)->hours();
    $second = $service->research($this->game);
    expect($second['report']->id)->not->toBe($first['report']->id)
        ->and($second['payload']['research_fingerprint'])->not->toBe($first['payload']['research_fingerprint']);
    Http::assertSentCount(2);
});

it('does not certify cached analysis when source collection becomes stale', function () {
    Http::fake(['*' => Http::response(suppliedSourceResponse())]);
    $service = app(NflWebContextResearchService::class);
    $service->research($this->game);
    $this->packet['holds'] = ['stale_or_missing_injury_MIA'];
    expect(fn () => $service->research($this->game))->toThrow(ResearchDeferred::class, 'research_source_evidence_not_ready');
    Http::assertSentCount(1);
});

it('limits supplied-source analysis independently of legacy production attempt overrides', function () {
    config(['nfl_research.cost_control.game_daily_attempts' => 99, 'nfl_research.cost_control.pregame_reserved_attempts' => 99]);
    $guard = app(ResearchSpendGuard::class);
    $reserve = fn ($fingerprint) => $guard->reserve($this->game, $fingerprint, 'openai', 'gpt-5.6-luna',
        fn ($metadata) => app(AiGenerationRecorder::class)->start('nfl_game_context_research', 'test', 'openai', 'gpt-5.6-luna', [], 'nfl_game', (string) $this->game->id, $metadata));
    $reserve('first');
    $this->travel(1)->hours();
    expect(fn () => $reserve('changed'))->toThrow(ResearchDeferred::class, 'research_retry_not_due');
    $this->travel(5)->hours();
    $reserve('changed');
    $this->travel(6)->hours();
    expect(fn () => $reserve('third'))->toThrow(ResearchDeferred::class, 'research_game_attempt_limit_reached');
    $this->game->game_date = now()->addHours(8)->utc()->toDateString();
    $this->game->game_time = now()->addHours(8)->utc()->format('H:i:s');
    $third = $reserve('pregame');
    expect($third->metadata['attempt_limit'])->toBe(3);
    $this->travel(20)->minutes();
    expect(fn () => $reserve('fourth'))->toThrow(ResearchDeferred::class, 'research_game_attempt_limit_reached');
});
