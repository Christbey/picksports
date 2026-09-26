<?php

use App\Models\BetDecision;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Services\CFB\CfbMarketMovementSignalService;
use App\Services\CFB\Predictions\CfbFrozenDecisionPerformance;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function frozenDecisionFixture(array $attributes = []): array
{
    [$home, $away] = Team::factory()->count(2)->create()->all();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'game_time' => '18:00:00',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL', 'home_score' => 35, 'away_score' => 14]);
    $decision = BetDecision::create([...['decision_run_id' => (string) Str::uuid(), 'sport' => 'cfb',
        'game_table' => 'cfb_games', 'game_id' => $game->id, 'market_type' => 'spread', 'market_key' => 'spreads',
        'side' => 'home', 'line' => -20.5, 'price' => -110, 'bookmaker' => 'entrybook', 'status' => 'released_tracking_bet',
        'is_bet' => true, 'is_tracking_only' => true, 'pregame_safe' => true, 'decided_at' => now(), 'locked_at' => now(),
        'game_start_at' => '2026-09-19 18:00:00', 'decision_hash' => hash('sha256', Str::uuid())], ...$attributes]);

    return [$game, $decision];
}

function frozenCfbQuote(Game $game, array $attributes = []): MarketQuote
{
    $snapshot = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'odds_api', 'captured_at' => now(), 'payload_hash' => hash('sha256', Str::uuid()), 'odds_data' => []]);

    return MarketQuote::create([...['game_odds_snapshot_id' => $snapshot->id, 'sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'odds_api', 'bookmaker_key' => 'lastbook', 'market_key' => 'spreads', 'side' => 'home',
        'line' => -22.5, 'bookmaker_home_line' => -22.5, 'price' => -110, 'captured_at' => now(), 'is_pregame' => true,
        'quote_hash' => hash('sha256', Str::uuid())], ...$attributes]);
}

it('grades ATS and priced returns and uses the last stored pregame quote from the entry book', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game, $decision] = frozenDecisionFixture();
    $entryClosing = frozenCfbQuote($game, ['bookmaker_key' => 'entrybook', 'line' => -21]);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 17:59:00'));
    $closing = frozenCfbQuote($game);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 18:00:00'));
    frozenCfbQuote($game, ['line' => -30]); // Exactly at kickoff is not pregame.
    $this->travelTo(CarbonImmutable::parse('2026-09-19 21:00:00'));
    frozenCfbQuote($game, ['line' => -40, 'captured_at' => '2026-09-19 17:59:30']); // Late imported quote is excluded.
    $service = app(CfbFrozenDecisionPerformance::class);
    $row = $service->grade($decision, $game);
    expect($row['grade'])->toBe('win')->and($row['profit_units'])->toBe(100 / 110)
        ->and($row['clv'])->toBe(0.5)->and($row['closing']['quote_id'])->toBe($entryClosing->id)
        ->and($row['cohort'])->toBe('tracked_recommendation');
    $movement = app(CfbMarketMovementSignalService::class)->withClosingLineValue($game, ['model_pick_side' => 'home', 'current_home_margin' => 20.5]);
    expect($movement['closing_line_value_points'])->toBe(2.0);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb'])->assertSuccessful();
    expect((int) $decision->fresh('settlement')->settlement->metadata['closing_quote_id'])->toBe($entryClosing->id)
        ->and($decision->settlement->metadata['closing_quote_selection'])->toBe('last_stored_pregame_quote');
});

it('grades pushes and missing-price outcomes without manufacturing returns', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game, $decision] = frozenDecisionFixture(['line' => -21, 'price' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 21:00:00'));
    $service = app(CfbFrozenDecisionPerformance::class);
    $row = $service->grade($decision, $game);
    expect($row['grade'])->toBe('push')->and($row['profit_units'])->toBeNull()
        ->and($row['missing'])->toContain('valid_entry_price', 'stored_pregame_closing_quote')
        ->and($service->summarize([$row])['priced_decisions'])->toBe(0)
        ->and($service->summarize([$row])['roi_per_unit_staked'])->toBeNull();
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'cfb'])->assertSuccessful();
    expect($decision->settlement)->toBeNull();
});

it('does not report hindsight decisions as frozen recommendation performance', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 21:00:00'));
    [$game, $decision] = frozenDecisionFixture();
    $row = app(CfbFrozenDecisionPerformance::class)->grade($decision, $game);
    expect($row['grade'])->toBeNull()->and($row['missing'])->toBe(['not_a_verified_frozen_pregame_decision']);
});

it('honors snapshot capture time for market spread comparison quotes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game] = frozenDecisionFixture();
    $early = frozenCfbQuote($game, ['line' => -19.5]);
    $asOf = now()->toImmutable();
    $this->travelTo(CarbonImmutable::parse('2026-09-19 11:00:00'));
    frozenCfbQuote($game, ['line' => -35]);
    $quote = app(CfbStoredPregameQuote::class)->latest($game->id, 'spreads', 'home', CarbonImmutable::parse('2026-09-19 18:00:00'), $asOf);
    expect($quote->id)->toBe($early->id);
});

it('uses storage order rather than provider capture order for the closing quote', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game] = frozenDecisionFixture();
    frozenCfbQuote($game, ['captured_at' => '2026-09-19 09:59:00', 'line' => -21]);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 11:00:00'));
    $lastStored = frozenCfbQuote($game, ['captured_at' => '2026-09-19 09:58:00', 'line' => -23]);
    $quote = app(CfbStoredPregameQuote::class)->latest($game->id, 'spreads', 'home', CarbonImmutable::parse('2026-09-19 18:00:00'));
    expect($quote->id)->toBe($lastStored->id);
});

it('regrades corrected results read-only while retaining the original frozen entry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game, $decision] = frozenDecisionFixture(['price' => 120]);
    $entry = $decision->fresh()->toArray();
    $this->travelTo(CarbonImmutable::parse('2026-09-19 21:00:00'));
    $service = app(CfbFrozenDecisionPerformance::class);
    expect($service->grade($decision, $game)['profit_units'])->toBe(1.2);
    $game->update(['home_score' => 14, 'away_score' => 35]);
    $row = $service->grade($decision, $game);
    expect($row['grade'])->toBe('loss')->and($row['profit_units'])->toBe(-1.0)
        ->and($decision->fresh()->toArray())->toBe($entry);
});

it('keeps closing quotes within the frozen participant and overtime contract', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00'));
    [$game, $decision] = frozenDecisionFixture(['market_key' => 'team_totals', 'market_type' => 'team_total', 'side' => 'over']);
    $attributes = ['market_key' => 'team_totals', 'side' => 'over', 'bookmaker_key' => 'entrybook', 'participant' => 'Home team', 'line' => 28.5,
        'metadata' => ['period' => 'full_game', 'includes_overtime' => true]];
    $entry = frozenCfbQuote($game, $attributes);
    $decision->update(['market_snapshot' => ['quote' => ['quote_id' => $entry->id]]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 17:00:00'));
    $closing = frozenCfbQuote($game, [...$attributes, 'line' => 30.5]);
    frozenCfbQuote($game, [...$attributes, 'participant' => 'Away team', 'line' => 20.5]);
    frozenCfbQuote($game, [...$attributes, 'metadata' => ['period' => 'full_game', 'includes_overtime' => false]]);
    $resolved = app(CfbStoredPregameQuote::class)->forDecision($decision, CarbonImmutable::parse('2026-09-19 18:00:00'));
    expect($resolved->id)->toBe($closing->id);
    $closing->metadata = [...$closing->metadata, 'provider_observed_at' => '2026-09-19 17:01:00'];
    expect(CfbStoredPregameQuote::identity($entry))->toBe(CfbStoredPregameQuote::identity($closing));
});
