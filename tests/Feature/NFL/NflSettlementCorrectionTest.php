<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Str;

it('corrects NFL outcomes with an audit trail while preserving pregame entries and non-bet accounting', function () {
    $this->travelTo('2026-09-17 12:00:00');
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addHours(8), 'game_time' => '20:00:00',
        'home_score' => null, 'away_score' => null,
    ]);
    foreach (['model_no_bet' => false, 'released_tracking_bet' => true, 'model_hold' => false] as $status => $isBet) {
        BetDecision::create([
            'decision_hash' => hash('sha256', $status), 'decision_run_id' => (string) Str::uuid(),
            'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
            'market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home',
            'line' => -3, 'price' => -110, 'bookmaker' => 'testbook', 'status' => $status,
            'is_bet' => $isBet, 'is_tracking_only' => true, 'is_public' => false, 'pregame_safe' => true,
            'decided_at' => now(), 'game_start_at' => now()->addHours(8),
            'market_snapshot' => ['immutable_entry_quote' => true, 'line' => -3, 'price' => -110],
        ]);
    }
    $originalEntries = BetDecision::orderBy('id')->get()->toArray();
    $this->travel(1)->day();
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->expectsOutput('Settled 2 decision(s).')->assertSuccessful();
    expect(BetSettlement::where('result_status', 'win')->count())->toBe(2);
    $firstSettledAt = BetSettlement::first()->settled_at->toIso8601String();

    // Correct within the same second: score values, not timestamps, drive repair.
    $game->update(['home_score' => 20, 'away_score' => 27]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])
        ->expectsOutput('Settled 0 decision(s).')
        ->expectsOutput('Corrected 2 NFL settlement(s) from changed official final scores.')->assertSuccessful();
    foreach (BetSettlement::with('decision')->get() as $settlement) {
        expect($settlement->result_status)->toBe('loss')
            ->and((float) $settlement->profit_units)->toBe($settlement->decision->is_bet ? -1.0 : 0.0)
            ->and((float) data_get($settlement->metadata, 'shadow_profit_units'))->toBe(-1.0)
            ->and(data_get($settlement->metadata, 'result_corrections.0.previous_result_status'))->toBe('win')
            ->and(data_get($settlement->metadata, 'result_corrections.0.previous_home_score'))->toBe(27)
            ->and($settlement->settled_at->toIso8601String())->toBe($firstSettledAt);
    }
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->expectsOutput('Settled 0 decision(s).')->assertSuccessful();
    expect(BetSettlement::count())->toBe(2)
        ->and(data_get(BetSettlement::first()->metadata, 'result_corrections'))->toHaveCount(1)
        ->and(BetDecision::orderBy('id')->get()->toArray())->toBe($originalEntries);
    $this->travelBack();
});
