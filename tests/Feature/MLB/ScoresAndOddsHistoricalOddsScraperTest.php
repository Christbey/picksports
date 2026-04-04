<?php

use App\Services\OddsApi\OddsApiService;
use App\Services\ScoresAndOdds\MLB\HistoricalOddsScraper;

it('parses mlb event cards from the scores and odds date page', function () {
    $html = <<<'HTML'
    <div id="mlb.7528700" class="event-card">
      <table class="event-card-table">
        <thead>
          <tr class="event-card-header">
            <th>
              <span data-field="state">FINAL</span>
              <span data-field="state" data-role="localtime" data-value="2025-04-03T23:40:00Z"></span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr class="event-card-row" data-side="away">
            <td>
              <span class="team-rotation">953</span>
              <span class="team-name"><a><span>Reds</span></a></span>
            </td>
            <td class="event-card-score loss">0</td>
            <td data-field="live-moneyline"><span class="data-value">even</span></td>
            <td data-field="live-total"><span class="data-value">o8</span><small class="data-odds">-112</small></td>
            <td data-field="live-spread"><span class="data-value">-1.5</span><small class="data-odds">+160</small></td>
          </tr>
          <tr class="event-card-row" data-side="home">
            <td>
              <span class="team-rotation">954</span>
              <span class="team-name"><a><span>Brewers</span></a></span>
            </td>
            <td class="event-card-score win">1</td>
            <td data-field="live-moneyline"><span class="data-value">-120</span></td>
            <td data-field="live-total"><span class="data-value">u8</span><small class="data-odds">-108</small></td>
            <td data-field="live-spread"><span class="data-value">+1.5</span><small class="data-odds">-192</small></td>
          </tr>
        </tbody>
      </table>
    </div>
    HTML;

    $scraper = new HistoricalOddsScraper(app(OddsApiService::class));
    $events = $scraper->parseDatePage($html);

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe('7528700')
        ->and($events[0]['commence_time'])->toBe('2025-04-03T23:40:00Z')
        ->and($events[0]['away_team'])->toBe('Reds')
        ->and($events[0]['home_team'])->toBe('Brewers')
        ->and($events[0]['summary']['away_moneyline'])->toBe(100)
        ->and($events[0]['summary']['home_total']['odds'])->toBe('-108');
});

it('parses draftkings odds from the scores and odds detail page', function () {
    $html = <<<'HTML'
    <div class="event-scoreboard">
      <span data-role="localtime" data-format="long" data-value="2025-04-03T23:40:00Z"></span>
    </div>
    <table>
      <thead>
        <tr>
          <th class="game-team" colspan="2"></th>
          <th class="book-logo"><img alt="bet365" /></th>
          <th class="book-logo"><img alt="draftkings" /></th>
        </tr>
      </thead>
      <tbody id="odds-table-moneyline--7528700">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Reds</span></a></span></td>
          <td class="game-odds"><span class="data-moneyline">+105</span></td>
          <td class="game-odds"><span class="data-moneyline">even</span></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Brewers</span></a></span></td>
          <td class="game-odds"><span class="data-moneyline">-125</span></td>
          <td class="game-odds"><span class="data-moneyline">-120</span></td>
        </tr>
      </tbody>
      <tbody id="odds-table-spread--7528700">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Reds</span></a></span></td>
          <td class="game-odds"><span class="data-value">+1.5</span><small class="data-odds">-200</small></td>
          <td class="game-odds"><span class="data-value">-1.5</span><small class="data-odds">+160</small></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Brewers</span></a></span></td>
          <td class="game-odds"><span class="data-value">-1.5</span><small class="data-odds">+170</small></td>
          <td class="game-odds"><span class="data-value">+1.5</span><small class="data-odds">-192</small></td>
        </tr>
      </tbody>
      <tbody id="odds-table-total--7528700">
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Reds</span></a></span></td>
          <td class="game-odds"><span class="data-value">o8</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">o8</span><small class="data-odds">-112</small></td>
        </tr>
        <tr>
          <td></td>
          <td class="game-team"><span class="team-name"><a><span>Brewers</span></a></span></td>
          <td class="game-odds"><span class="data-value">u8</span><small class="data-odds">-110</small></td>
          <td class="game-odds"><span class="data-value">u8</span><small class="data-odds">-108</small></td>
        </tr>
      </tbody>
    </table>
    HTML;

    $scraper = new HistoricalOddsScraper(app(OddsApiService::class));
    $event = $scraper->parseEventDetails($html, '7528700');

    expect($event)->not->toBeNull()
        ->and($event['odds_data']['bookmakers'][0]['key'])->toBe('draftkings')
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.0.outcomes.0.price'))->toBe(100)
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.1.outcomes.0.point'))->toBe(-1.5)
        ->and(data_get($event, 'odds_data.bookmakers.0.markets.2.outcomes.0.point'))->toBe(8.0)
        ->and(data_get($event, 'odds_data.market_context.has_totals'))->toBeTrue();
});
