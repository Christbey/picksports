<?php

use App\Services\OddsApi\Exceptions\OddsApiException;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Support\Facades\Http;

it('formats historical odds timestamps with a trailing zulu designator', function () {
    config()->set('services.odds_api.key', 'test-key');

    Http::fake([
        'https://api.the-odds-api.com/v4/historical/sports/basketball_nba/odds*' => Http::response([
            'timestamp' => '2025-12-24T16:55:37Z',
            'data' => [],
        ], 200),
    ]);

    $service = app(OddsApiService::class);
    $service->getHistoricalOdds('basketball_nba', '2025-12-24T17:00:00+00:00');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.the-odds-api.com/v4/historical/sports/basketball_nba/odds?apiKey=test-key&regions=us&markets=h2h%2Cspreads%2Ctotals&bookmakers=draftkings&oddsFormat=american&date=2025-12-24T17%3A00%3A00Z';
    });
});

it('throws a clear exception when the odds api is out of credits', function () {
    config()->set('services.odds_api.key', 'test-key');

    Http::fake([
        'https://api.the-odds-api.com/v4/historical/sports/baseball_mlb/odds*' => Http::response([
            'message' => 'Usage quota has been reached.',
            'error_code' => 'OUT_OF_USAGE_CREDITS',
        ], 401),
    ]);

    $service = app(OddsApiService::class);

    expect(fn () => $service->getHistoricalOdds('baseball_mlb', '2025-04-01T17:00:00Z'))
        ->toThrow(OddsApiException::class, 'OUT_OF_USAGE_CREDITS');
});

it('reuses an NFL game odds response for four hours while retaining its original timestamp', function () {
    config()->set('services.odds_api.key', 'nfl-cache-test');
    $this->travelTo('2026-09-27 06:10:00');
    $observedAt = now()->toIso8601String();
    Http::fake(['*' => Http::response([['id' => 'game', 'last_update' => $observedAt]])]);
    $service = app(OddsApiService::class);
    $service->getOdds('americanfootball_nfl');
    $this->travel(3)->hours();
    expect($service->getOdds('americanfootball_nfl')[0]['last_update'])->toBe($observedAt);
    Http::assertSentCount(1);
    $this->travel(61)->minutes();
    $service->getOdds('americanfootball_nfl');
    Http::assertSentCount(2);
    $this->travelBack();
});

it('pauses uncached requests across the account for 24 hours after quota exhaustion', function () {
    config()->set('services.odds_api.key', 'quota-cooldown-test');
    $this->travelTo('2026-09-27 06:10:00');
    Http::fakeSequence()
        ->push([['id' => 'cached-nfl']], 200)
        ->push(['error_code' => 'OUT_OF_USAGE_CREDITS', 'message' => 'Usage quota has been reached.'], 401)
        ->push([], 200);
    $service = app(OddsApiService::class);
    $service->getOdds('americanfootball_nfl');
    expect(fn () => $service->getOdds('baseball_mlb'))->toThrow(OddsApiException::class, 'OUT_OF_USAGE_CREDITS');
    expect($service->getOdds('americanfootball_nfl'))->toBe([['id' => 'cached-nfl']]);
    expect(fn () => $service->getHistoricalOdds('basketball_nba', '2026-09-25T00:00:00Z'))
        ->toThrow(OddsApiException::class, 'requests paused');
    $this->travel(23)->hours();
    expect(fn () => $service->getOdds('americanfootball_nfl'))->toThrow(OddsApiException::class, 'requests paused');
    Http::assertSentCount(2);
    $this->travel(61)->minutes();
    $service->getOdds('americanfootball_nfl');
    Http::assertSentCount(3);
    $this->travelBack();
});

it('does not apply an old account cooldown to a replacement API key', function () {
    config()->set('services.odds_api.key', 'old-account');
    (new OddsApiService)->pauseForQuotaExhaustion();
    config()->set('services.odds_api.key', 'replacement-account');
    Http::fake(['*' => Http::response([], 200)]);
    (new OddsApiService)->getOdds('americanfootball_nfl');
    Http::assertSentCount(1);
});
