<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.settlement_writer' => config('database.connections.mysql')]);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('settlement_writer');
});

it('settles from current locked scores without undoing a newer receipt behind an older snapshot', function (bool $newerReceipt) {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $decision = BetDecision::query()->create([
        'decision_hash' => hash('sha256', (string) Str::uuid()), 'decision_run_id' => (string) Str::uuid(),
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home', 'line' => -3, 'price' => -110,
        'status' => 'released_tracking_bet', 'is_bet' => true, 'is_tracking_only' => true,
        'pregame_safe' => true, 'decided_at' => now()->subDay(), 'game_start_at' => now()->subHours(4),
    ]);
    DB::beginTransaction();
    DB::table('nfl_games')->where('id', $game->id)->first();
    DB::connection('settlement_writer')->table('nfl_games')->where('id', $game->id)->update(['home_score' => 20, 'away_score' => 27]);
    $receipt = $newerReceipt ? BetSettlement::on('settlement_writer')->create([
        'bet_decision_id' => $decision->id, 'result_status' => 'loss', 'result_value' => -7,
        'profit_units' => -1, 'graded_at' => now(), 'settled_at' => now()->subHour(),
        'closing_price' => -115, 'closing_line' => -3.5, 'clv' => 0.5,
        'metadata' => ['home_score' => 20, 'away_score' => 27, 'closing_quote_id' => 123,
            'closing_bookmaker' => 'fixture', 'result_corrections' => [['reason' => 'preserved_prior_correction']]],
    ]) : null;
    $before = $receipt?->fresh()->getAttributes();

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->assertSuccessful();
    DB::commit();
    $current = BetSettlement::query()->where('bet_decision_id', $decision->id)->sole();
    expect($current->result_status)->toBe('loss')
        ->and((float) $current->profit_units)->toBe(-1.0)
        ->and(data_get($current->metadata, 'home_score'))->toBe(20)
        ->and(data_get($current->metadata, 'away_score'))->toBe(27);
    if ($before !== null) {
        expect($current->getAttributes())->toBe($before);
    }
})->with([false, true]);
