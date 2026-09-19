<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\CfbSpreadMarketConfirmation;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Support\Facades\Http;

function confirmationGame(): Game
{
    $start = now()->addHours(4);

    return Game::factory()->create(['home_team_id' => Team::factory()->create(['school' => 'Home State'])->id,
        'away_team_id' => Team::factory()->create(['school' => 'Away Tech'])->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s')]);
}

function confirmationQuotes(Game $game, array $books, ?string $observed = null): void
{
    $event = ['id' => 'event-'.$game->id, 'commence_time' => now()->addHours(4)->toIso8601String(),
        'home_team' => 'Home State', 'away_team' => 'Away Tech',
        'bookmakers' => array_map(fn ($book) => ['key' => $book[0], 'title' => $book[0],
            'last_update' => $observed ?? now()->subMinute()->toIso8601String(),
            'markets' => [['key' => 'spreads', 'outcomes' => [
                ['name' => 'Home State', 'point' => $book[1], 'price' => $book[2] ?? -110],
                ['name' => 'Away Tech', 'point' => -$book[1], 'price' => -110],
            ]]]], $books)];
    app(GameOddsSnapshotRecorder::class)->record('cfb', $game, $event, app(OddsApiService::class)->extractOddsData($event));
}

it('requests actual multiple CFB books and preserves provider quote timestamps', function () {
    config(['services.odds_api.key' => 'test', 'services.odds_api.base_url' => 'https://odds.test']);
    Http::fake(['*' => Http::response([])]);
    app(OddsApiService::class)->getOdds('americanfootball_ncaaf');
    Http::assertSent(fn ($request) => $request['bookmakers'] === 'draftkings,fanduel,betmgm');
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20], ['fanduel', -19.5]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeTrue()->and($result['confirming_book_count'])->toBe(2)
        ->and($result['best_quote']['bookmaker'])->toBe('fanduel')->and($result['best_quote']['line'])->toBe(-19.5)
        ->and($result['best_quote']['provider_observed_at'])->not->toBeNull();
});

it('requires two distinct books and will not count duplicate quotes as confirmation', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20]]);
    confirmationQuotes($game, [['draftkings', -19.5]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeFalse()->and($result['fresh_book_count'])->toBe(1);
});

it('rejects provider-stale quotes even when ingestion was fresh', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20], ['fanduel', -20]], now()->subHours(2)->toIso8601String());
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeFalse()->and($result['fresh_book_count'])->toBe(0)
        ->and($result['rejected_quotes'][0]['risk_flags'])->toContain('stale_or_future_market_quote');
});

it('holds outlier markets rather than selecting an isolated attractive line', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20], ['fanduel', -20], ['betmgm', -10]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeFalse()->and($result['risk_flags'])->toContain('wide_bookmaker_line_range')
        ->and($result['best_quote']['line'])->toBe(-20.0);
});

it('checks executable prices and selects the better price at an equal handicap', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20, -120], ['fanduel', -20, -105], ['betmgm', -19, -150]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeTrue()->and($result['best_quote']['bookmaker'])->toBe('fanduel')
        ->and($result['best_quote']['price'])->toBe(-105)->and($result['rejected_quotes'][0]['risk_flags'])->toContain('unacceptable_spread_price');
});

it('requires a qualifying point edge on the same side at both books', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', -20], ['fanduel', -22.5]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 25);
    expect($result['supported'])->toBeFalse()->and($result['confirming_book_count'])->toBe(1);
});

it('preserves fresh Auburn plus 2.5 prices below the betting edge threshold', function () {
    $game = confirmationGame();
    confirmationQuotes($game, [['draftkings', 2.5, -115], ['fanduel', 2.5, -115], ['betmgm', 2, -108]]);
    $result = app(CfbSpreadMarketConfirmation::class)->assess($game, 'home', 0);
    expect($result['supported'])->toBeFalse()->and($result['market_supported'])->toBeTrue()
        ->and($result['fresh_book_count'])->toBe(3)->and($result['confirming_book_count'])->toBe(0)
        ->and($result['best_quote']['line'])->toBe(2.5)->and($result['best_quote']['price'])->toBe(-115)
        ->and($result['best_quote']['edge_points'])->toBe(2.5);
});
