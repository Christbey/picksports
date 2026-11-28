<?php

use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Team;
use App\Services\MLB\Picks\BetDecisionRecorder;
use App\Services\MLB\Picks\MlbPickGradingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.mlb_result_writer' => config('database.connections.mysql')]);
});

afterEach(function () {
    DB::setDefaultConnection($this->originalConnection);
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('mlb_result_writer');
});

it('uses current locked MLB scores behind an older snapshot and preserves a newer receipt', function ($newerReceipt) {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 2]);
    $candidate = PickCandidate::create(['season' => 2026, 'game_id' => $game->id,
        'generation_run_id' => (string) Str::uuid(), 'market_type' => 'moneyline', 'market_key' => 'h2h',
        'side' => 'home', 'price' => -110, 'is_bet' => true, 'generated_at' => now()->subHours(3), 'game_start_at' => now()->subHours(2)]);
    $decision = app(BetDecisionRecorder::class)->record($candidate->refresh());
    DB::beginTransaction();
    DB::table('mlb_games')->where('id', $game->id)->first();
    DB::connection('mlb_result_writer')->table('mlb_games')->where('id', $game->id)->update(['home_score' => 1, 'away_score' => 6]);
    $before = null;
    if ($newerReceipt) {
        DB::setDefaultConnection('mlb_result_writer');
        $receipt = app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
        $before = $receipt->fresh()->getAttributes();
        DB::setDefaultConnection($this->originalConnection);
    }
    $receipt = app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
    DB::commit();
    expect($receipt->fresh()->result_status)->toBe('loss')
        ->and((float) $receipt->fresh()->profit_units)->toBe(-1.0)
        ->and($candidate->fresh()->result_status)->toBe('loss')
        ->and($receipt->fresh()->metadata['home_score'])->toBe(1);
    if ($before !== null) {
        expect($receipt->fresh()->getAttributes())->toBe($before);
    }
})->with([false, true]);
