<?php

use App\Models\NFL\Game;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\NFL\Research\ResearchUncertaintyPolicy;

uses()->group('nfl', 'ai');

it('retains assumptions without blocking normal pregame uncertainty', function (string $reason) {
    $item = app(ResearchUncertaintyPolicy::class)->normalize(['reason_code' => $reason, 'scope' => 'game', 'blocking' => true]);
    expect($item['blocking'])->toBeFalse()->and($item['requested_blocking'])->toBeTrue()
        ->and($item['policy_disposition'])->toBe('conditional_analysis');
})->with(['routine_starter_confirmation', 'future_report', 'future_usage', 'forecast_variance']);

it('never clears real or unclassified blockers and forces model conflicts to remain held', function () {
    $policy = app(ResearchUncertaintyPolicy::class);
    foreach (['material_availability', 'source_gap', 'unknown'] as $reason) {
        expect($policy->normalize(['reason_code' => $reason, 'scope' => 'game', 'blocking' => true])['blocking'])->toBeTrue();
    }
    $conflict = $policy->normalize(['reason_code' => 'model_input_conflict', 'scope' => 'informational', 'blocking' => false]);
    expect($conflict['scope'])->toBe('game')->and($conflict['blocking'])->toBeTrue();
});

function uncertaintyPayload(array $items): array
{
    return ['status' => 'insufficient', 'risk_flags' => [], 'sources' => [['url' => 'https://example.com/official']],
        'facts' => [['team_side' => 'both', 'source_urls' => ['https://example.com/official']]],
        'decision_research' => ['supporting' => [['claim' => 'Support']], 'opposing' => [['claim' => 'Oppose']], 'unresolved' => $items]];
}

it('separates total-only holds from usable game research without hiding the hold', function () {
    $payload = uncertaintyPayload([['reason_code' => 'market_data_gap', 'scope' => 'total', 'blocking' => true]]);
    $result = app(ResearchUncertaintyPolicy::class)->applyStatus($payload);
    expect($result['status'])->toBe('ready')->and($result['researcher_status'])->toBe('insufficient')
        ->and($result['decision_research']['unresolved'][0]['blocking'])->toBeTrue();
    $policy = app(ResearchUncertaintyPolicy::class);
    expect($policy->holdsFor($result['decision_research']['unresolved'], 'spread'))->toBe([])
        ->and($policy->holdsFor($result['decision_research']['unresolved'], 'total'))->toHaveCount(1);
});

it('propagates a whole-game model conflict to every market including props', function () {
    $policy = app(ResearchUncertaintyPolicy::class);
    $items = [$policy->normalize(['reason_code' => 'model_input_conflict', 'scope' => 'informational', 'blocking' => false])];
    foreach (['spread', 'total', 'moneyline', 'props'] as $market) {
        expect($policy->holdsFor($items, $market))->toHaveCount(1);
    }
});

it('requires both teams and both sides of evidence before clearing an AI completeness label', function () {
    $policy = app(ResearchUncertaintyPolicy::class);
    $payload = uncertaintyPayload([['reason_code' => 'future_report', 'scope' => 'game', 'blocking' => false]]);
    expect($policy->applyStatus($payload)['status'])->toBe('ready');
    foreach (['facts', 'sources'] as $key) {
        $missing = $payload;
        $missing[$key] = [];
        expect($policy->applyStatus($missing)['status'])->toBe('insufficient');
    }
    $payload['risk_flags'] = ['source_conflict:player'];
    expect($policy->applyStatus($payload)['status'])->toBe('insufficient');
});

it('downgrades a ready report with a real whole-game blocker', function () {
    $payload = uncertaintyPayload([['reason_code' => 'material_availability', 'scope' => 'game', 'blocking' => true]]);
    $payload['status'] = 'ready';
    expect(app(ResearchUncertaintyPolicy::class)->applyStatus($payload)['status'])->toBe('partial');
});

it('supplies fresh paired totals and moneylines alongside spreads in the actual research input', function () {
    $game = new Game(['odds_updated_at' => now(), 'game_date' => now()->addDay()->toDateString(), 'game_time' => '20:00:00',
        'odds_data' => ['home_team' => 'Home', 'away_team' => 'Away', 'bookmakers' => [['key' => 'book', 'markets' => [
            ['key' => 'totals', 'last_update' => now()->toIso8601String(), 'outcomes' => [['name' => 'Over', 'point' => 45.5, 'price' => -110], ['name' => 'Under', 'point' => 45.5, 'price' => -110]]],
            ['key' => 'h2h', 'last_update' => now()->toIso8601String(), 'outcomes' => [['name' => 'Home', 'price' => -140], ['name' => 'Away', 'price' => 120]]],
        ]]]]]);
    $input = (new ReflectionMethod(NflWebContextResearchService::class, 'input'))->invoke(app(NflWebContextResearchService::class), $game);
    expect($input['synced_market']['total_quotes'])->toHaveCount(2)
        ->and($input['synced_market']['total_quotes'][0]['line'])->toBe(45.5)
        ->and($input['synced_market']['moneyline_quotes'])->toHaveCount(2);
    $data = $game->odds_data;
    $data['bookmakers'][0]['markets'][0]['outcomes'][1]['point'] = 46.5;
    $data['bookmakers'][0]['markets'][1]['last_update'] = now()->subHours(2)->toIso8601String();
    $game->odds_data = $data;
    expect(app(ResearchPipeline::class)->additionalMarketQuotes($game, 'totals'))->toBe([])
        ->and(app(ResearchPipeline::class)->additionalMarketQuotes($game, 'h2h'))->toBe([]);
});

it('applies reason rules through normalization while preserving uncited material questions', function () {
    $method = new ReflectionMethod(NflWebContextResearchService::class, 'decisionResearch');
    $base = ['claim' => 'Question', 'source_url' => '', 'scope' => 'game', 'blocking' => true];
    $result = $method->invoke(app(NflWebContextResearchService::class), ['unresolved' => [
        [...$base, 'reason_code' => 'future_report'], [...$base, 'reason_code' => 'material_availability'],
    ]], []);
    expect($result['unresolved'][0]['blocking'])->toBeFalse()
        ->and($result['unresolved'][1]['blocking'])->toBeTrue()
        ->and($result['unresolved'][1]['evidence_status'])->toBe('unverified_question');
});

it('rejects incomplete or incorrectly dated paired total prices', function (string $variant) {
    $market = ['key' => 'totals', 'last_update' => now()->toIso8601String(), 'outcomes' => [
        ['name' => 'Over', 'point' => 45.5, 'price' => -110], ['name' => 'Under', 'point' => 45.5, 'price' => -110],
    ]];
    if ($variant === 'future') {
        $market['last_update'] = now()->addHour()->toIso8601String();
    } elseif ($variant === 'invalid_date') {
        $market['last_update'] = 'not-a-date';
    } elseif ($variant === 'one_sided') {
        array_pop($market['outcomes']);
    } elseif ($variant === 'invalid_price') {
        $market['outcomes'][1]['price'] = 0;
    } elseif ($variant === 'missing_line') {
        unset($market['outcomes'][1]['point']);
    }
    $game = new Game(['odds_data' => ['bookmakers' => [['key' => 'book', 'markets' => [$market]]]]]);
    expect(app(ResearchPipeline::class)->additionalMarketQuotes($game, 'totals'))->toBe([]);
})->with(['future', 'invalid_date', 'one_sided', 'invalid_price', 'missing_line']);
