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
