<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use Illuminate\Support\Str;

function cfbRegradeFixture(?int $price = -110): array
{
    [$home, $away] = Team::factory()->count(2)->create()->all();
    $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'game_date' => '2026-09-19', 'game_time' => '18:00:00', 'home_score' => 28, 'away_score' => 14]);
    $decision = BetDecision::create(['decision_run_id' => (string) Str::uuid(), 'sport' => 'cfb', 'game_table' => 'cfb_games',
        'game_id' => $game->id, 'market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home',
        'line' => -7, 'price' => $price, 'bookmaker' => 'lastbook', 'status' => 'released_tracking_bet', 'is_bet' => true, 'is_tracking_only' => true,
        'pregame_safe' => true, 'decided_at' => now(), 'locked_at' => now(), 'game_start_at' => '2026-09-19 18:00:00',
        'decision_hash' => hash('sha256', Str::uuid())]);
    $settlement = BetSettlement::create(['bet_decision_id' => $decision->id, 'result_status' => 'win', 'result_value' => 14,
        'profit_units' => $price ? 100 / 110 : 0, 'closing_line' => -8, 'closing_price' => -110, 'clv' => 1,
        'graded_at' => now(), 'settled_at' => now(), 'metadata' => ['home_score' => 28, 'away_score' => 14,
            'closing_quote_selection' => 'consensus_fallback', 'shadow_profit_units' => $price ? 100 / 110 : 0]]);

    return [$game, $decision, $settlement];
}

it('explicitly regrades CFB score and closing values while preserving entries and an idempotent audit', function () {
    $this->travelTo('2026-09-19 10:00:00');
    [$game, $decision, $settlement] = cfbRegradeFixture();
    $entry = $decision->fresh()->toArray();
    $firstSettled = $settlement->settled_at->toIso8601String();
    $this->travelTo('2026-09-19 17:59:00');
    $snapshot = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'odds_api', 'captured_at' => now(), 'payload_hash' => hash('sha256', Str::uuid()), 'odds_data' => []]);
    $quote = MarketQuote::create(['game_odds_snapshot_id' => $snapshot->id, 'sport' => 'cfb', 'game_table' => 'cfb_games',
        'game_id' => $game->id, 'source' => 'odds_api', 'bookmaker_key' => 'lastbook', 'market_key' => 'spreads',
        'side' => 'home', 'line' => -10, 'price' => -115, 'captured_at' => now(), 'is_pregame' => true,
        'quote_hash' => hash('sha256', Str::uuid())]);
    $this->travelTo('2026-09-19 21:00:00');
    $game->update(['home_score' => 14, 'away_score' => 28]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb'])->assertSuccessful();
    expect($settlement->fresh()->result_status)->toBe('win'); // Explicit repair is required.
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    $updated = $settlement->fresh();
    expect($updated->result_status)->toBe('loss')->and((float) $updated->profit_units)->toBe(-1.0)
        ->and((float) $updated->closing_line)->toBe(-10.0)->and((float) $updated->clv)->toBe(3.0)
        ->and($updated->metadata['closing_quote_id'])->toBe($quote->id)
        ->and($updated->metadata['cfb_regrades'])->toHaveCount(1)
        ->and($updated->metadata['cfb_regrades'][0]['previous_settlement']['result_status'])->toBe('win')
        ->and((float) $updated->metadata['cfb_regrades'][0]['previous_settlement']['closing_line'])->toBe(-8.0)
        ->and($updated->settled_at->toIso8601String())->toBe($firstSettled)
        ->and($decision->fresh()->toArray())->toBe($entry);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    expect($settlement->fresh()->metadata['cfb_regrades'])->toHaveCount(1);
    $game->update(['home_score' => 35, 'away_score' => 14]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    expect($settlement->fresh()->metadata['cfb_regrades'])->toHaveCount(2)
        ->and($settlement->fresh()->result_status)->toBe('win');
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    expect($settlement->fresh()->metadata['cfb_regrades'])->toHaveCount(2);
});

it('repairs invented missing-price returns to null while retaining the previous settlement audit', function () {
    $this->travelTo('2026-09-19 10:00:00');
    [$game, $decision, $settlement] = cfbRegradeFixture(null);
    $this->travelTo('2026-09-19 21:00:00');
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    $updated = $settlement->fresh();
    expect($updated->result_status)->toBe('win')->and($updated->profit_units)->toBeNull()
        ->and($updated->metadata['shadow_profit_units'])->toBeNull()
        ->and($updated->metadata['return_status'])->toBe('missing_entry_price')
        ->and($updated->metadata['exclude_from_return_metrics'])->toBeTrue()
        ->and((float) $updated->metadata['cfb_regrades'][0]['previous_settlement']['profit_units'])->toBe(0.0);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb', '--regrade-cfb' => true])->assertSuccessful();
    expect($settlement->fresh()->metadata['cfb_regrades'])->toHaveCount(1);
});

it('requires an explicit CFB scope for settlement repair', function () {
    $this->artisan('sports:settle-bet-decisions', ['--regrade-cfb' => true])->assertFailed();
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl', '--regrade-cfb' => true])->assertFailed();
});
