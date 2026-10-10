<?php

use App\Models\BetDecision;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbFrozenSpreadEntryComparison;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19T15:00:00Z'));
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'starts_at' => '2026-09-19 18:00:00']);
    [$home, $away] = Team::factory()->count(2)->create()->all();
    $this->game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'week' => 3,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-19', 'game_time' => '18:00:00', 'home_score' => 21, 'away_score' => 14]);
    $team = ['metrics' => ['fpi' => 0], 'rating_evidence' => ['source' => 'cfbd_fpi',
        'units' => 'points_above_average', 'season' => 2026, 'observed_at' => '2026-09-18T00:00:00Z']];
    $inputs = ['event' => ['season' => 2026, 'neutral_site' => true], 'home' => $team, 'away' => $team];
    $inputs['home']['metrics']['fpi'] = -6;
    $this->snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
        'inputs' => $inputs, 'content_hash' => hash('sha256', json_encode($inputs))]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $this->snapshot->id, 'status' => 'succeeded']);
    $prediction = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
        'phase' => 'pregame', 'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'model_version' => 'test-release']);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread',
        'selection' => 'home', 'projected_line' => -10]);
    $prediction->forceFill(['publication_state' => 'published', 'published_at' => now()])->save();
    $this->odds = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $this->game->id,
        'source' => 'odds_api', 'captured_at' => now(), 'payload_hash' => hash('sha256', 'test-entry'), 'odds_data' => []]);
    $this->quote = function (string $side) {
        return MarketQuote::create(['game_odds_snapshot_id' => $this->odds->id, 'sport' => 'cfb',
            'game_table' => 'cfb_games', 'game_id' => $this->game->id, 'source' => 'odds_api',
            'bookmaker_key' => 'fanduel', 'market_key' => 'spreads', 'side' => $side,
            'line' => $side === 'home' ? -3.5 : 3.5, 'price' => $side === 'home' ? -110 : -105,
            'captured_at' => CarbonImmutable::parse('2026-09-19T15:00:00Z')->setTimezone(config('app.timezone')), 'is_pregame' => true,
            'metadata' => ['provider_observed_at' => '2026-09-19T15:00:00Z'],
            'quote_hash' => hash('sha256', Str::uuid())]);
    };
    $entry = ($this->quote)('home');
    $this->travelTo(now()->addMinute());
    $this->decision = BetDecision::create(['decision_run_id' => (string) Str::uuid(), 'sport' => 'cfb',
        'game_table' => 'cfb_games', 'game_id' => $this->game->id, 'market_type' => 'spread', 'market_key' => 'spreads', 'status' => 'provisional_candidate', 'side' => 'home',
        'line' => -3.5, 'price' => -110, 'bookmaker' => 'fanduel', 'is_bet' => false, 'pregame_safe' => true,
        'decided_at' => now(), 'locked_at' => now(), 'game_start_at' => $event->starts_at,
        'decision_hash' => hash('sha256', Str::uuid()),
        'market_snapshot' => ['quote' => ['quote_id' => $entry->id]],
        'feature_snapshot' => ['canonical_prediction_id' => $prediction->id, 'event_input_snapshot_id' => $this->snapshot->id,
            'content_hash' => $this->snapshot->content_hash]]);
});

it('compares margins and ATS sides on the exact entry line without imputing the opposite price', function () {
    $this->travelTo(now()->addHours(5));
    $row = app(CfbFrozenSpreadEntryComparison::class)->compare($this->decision, $this->game);
    expect($row['models']['official']['result'])->toBe('win')
        ->and($row['models']['fpi_baseline']['result'])->toBe('loss')
        ->and($row['models']['fpi_baseline']['shadow_profit_units'])->toBeNull();
});

it('rejects an opposite price uploaded after the original decision even when its capture time is backdated', function () {
    $this->travelTo(now()->addMinute());
    ($this->quote)('away');
    $this->travelTo(now()->addHours(5));
    $row = app(CfbFrozenSpreadEntryComparison::class)->compare($this->decision, $this->game);
    expect($row['models']['fpi_baseline']['price'])->toBeNull();
});

it('rejects a snapshot fingerprint that differs from the frozen entry', function () {
    $this->decision->feature_snapshot = [...$this->decision->feature_snapshot, 'content_hash' => 'wrong'];
    $row = app(CfbFrozenSpreadEntryComparison::class)->compare($this->decision, $this->game);
    expect($row['excluded'])->toBe('snapshot_not_linked_or_available_at_entry');
});

it('keeps subthreshold edges and pushes out of fabricated return metrics', function () {
    $service = app(CfbFrozenSpreadEntryComparison::class);
    expect($service->assess(5, -3.5, 7, ['home' => -110])['result'])->toBe('no_signal')
        ->and($service->assess(10, -7, 7, ['home' => -110])['shadow_profit_units'])->toBe(0.0)
        ->and($service->assess(-10, -3.5, 7, [])['shadow_profit_units'])->toBeNull();
});

it('uses the observed opposite-side price from the same entry snapshot', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19T15:00:00Z'));
    ($this->quote)('away');
    $this->travelTo(CarbonImmutable::parse('2026-09-19T21:00:00Z'));
    $row = app(CfbFrozenSpreadEntryComparison::class)->compare($this->decision, $this->game);
    expect($row['models']['fpi_baseline']['price'])->toBe(-105)
        ->and($row['models']['fpi_baseline']['shadow_profit_units'])->toBe(-1.0);
});

it('uses a frozen paired price without replacing it with a later quote', function () {
    $time = CarbonImmutable::parse('2026-09-19T15:00:00Z')->toIso8601String();
    $this->decision->market_snapshot = ['quote' => ['snapshot_id' => 123, 'bookmaker' => 'fanduel',
        'side' => 'home', 'line' => -3.5, 'contract' => [], 'opposite_quote' => [
            'snapshot_id' => 123, 'bookmaker' => 'fanduel', 'side' => 'away', 'line' => 3.5, 'contract' => [],
            'price' => -108, 'captured_at' => $time, 'stored_at' => $time, 'provider_observed_at' => $time,
        ]]];
    $this->travelTo(now()->addHours(5));
    $row = app(CfbFrozenSpreadEntryComparison::class)->compare($this->decision, $this->game);
    expect($row['models']['fpi_baseline']['price'])->toBe(-108);
});
