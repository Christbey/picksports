<?php

use App\Services\OddsApi\OddsApiService;
use App\Services\ScoresAndOdds\NFL\HistoricalOddsScraper;

uses()->group('nfl', 'odds');

it('parses nfl event cards from the scores and odds date page', function () {
    $html = <<<'HTML'
    <div id="nfl.1234567" class="event-card">
      <table class="event-card-table">
        <thead>
          <tr class="event-card-header">
            <th>
              <span data-field="state">FINAL</span>
              <span data-field="state" data-role="localtime" data-value="2025-09-07T20:25:00Z"></span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr class="event-card-row" data-side="away">
            <td>
              <span class="team-rotation">481</span>
              <span class="team-name"><a><span>Broncos</span></a></span>
            </td>
            <td class="event-card-score loss">20</td>
            <td data-field="live-moneyline"><span class="data-value">+136</span></td>
            <td data-field="live-total"><span class="data-value">o42.5</span><small class="data-odds">-110</small></td>
            <td data-field="live-spread"><span class="data-value">+3</span><small class="data-odds">-115</small></td>
          </tr>
          <tr class="event-card-row" data-side="home">
            <td>
              <span class="team-rotation">482</span>
              <span class="team-name"><a><span>Chiefs</span></a></span>
            </td>
            <td class="event-card-score win">24</td>
            <td data-field="live-moneyline"><span class="data-value">-162</span></td>
            <td data-field="live-total"><span class="data-value">u42.5</span><small class="data-odds">-110</small></td>
            <td data-field="live-spread"><span class="data-value">-3</span><small class="data-odds">-105</small></td>
          </tr>
        </tbody>
      </table>
    </div>
    HTML;

    $scraper = new HistoricalOddsScraper(app(OddsApiService::class));
    $events = $scraper->parseDatePage($html);

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe('1234567')
        ->and($events[0]['commence_time'])->toBe('2025-09-07T20:25:00Z')
        ->and($events[0]['away_team'])->toBe('Broncos')
        ->and($events[0]['home_team'])->toBe('Chiefs')
        ->and($events[0]['summary']['away_moneyline'])->toBe(136)
        ->and($events[0]['summary']['home_spread']['value'])->toBe('-3');
});

it('parses draftkings nfl h2h spread and total odds from the detail page', function () {
    $html = <<<'HTML'
    <div class="event-scoreboard">
      <span data-role="localtime" data-format="long" data-value="2025-09-07T20:25:00Z"></span>
    </div>
    <table>
      <thead>
        <tr>
          <th class="game-team" colspan="2"></th>
          <th class="book-logo"><img alt="bet365" /></th>
          <th class="book-logo"><img alt="draftkings" /></th>
        </tr>
      </thead>
      <tbody id="odds-table-moneyline--1234567">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Broncos</span></a></span></td>
          <td class="game-odds"><span class="data-moneyline">+140</span></td>
          <td class="game-odds"><span class="data-moneyline">+136</span></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Chiefs</span></a></span></td>
          <td class="game-odds"><span class="data-moneyline">-165</span></td>
          <td class="game-odds"><span class="data-moneyline">-162</span></td>
        </tr>
      </tbody>
      <tbody id="odds-table-spread--1234567">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Broncos</span></a></span></td>
          <td class="game-odds"><span class="data-value">+3</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">+3</span><small class="data-odds">-115</small></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Chiefs</span></a></span></td>
          <td class="game-odds"><span class="data-value">-3</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">-3</span><small class="data-odds">-105</small></td>
        </tr>
      </tbody>
      <tbody id="odds-table-total--1234567">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Broncos</span></a></span></td>
          <td class="game-odds"><span class="data-value">o42.5</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">o42.5</span><small class="data-odds">-110</small></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Chiefs</span></a></span></td>
          <td class="game-odds"><span class="data-value">u42.5</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">u42.5</span><small class="data-odds">-110</small></td>
        </tr>
      </tbody>
    </table>
    HTML;

    $scraper = new HistoricalOddsScraper(app(OddsApiService::class));
    $event = $scraper->parseEventDetails($html, '1234567');

    expect($event)->not->toBeNull()
        ->and($event['odds_data']['bookmakers'][0]['key'])->toBe('draftkings')
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.0.key'))->toBe('h2h')
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.0.outcomes.0.price'))->toBe(136)
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.1.outcomes.1.point'))->toBe(-3.0)
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.2.outcomes.0.point'))->toBe(42.5)
        ->and(data_get($event, 'odds_data.market_context.has_h2h'))->toBeTrue();
});
