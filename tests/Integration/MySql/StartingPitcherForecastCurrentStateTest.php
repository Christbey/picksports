<?php

use App\Models\MLB\Game;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\MLB\Team;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Forecast current-state tests require disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    config(['database.connections.starter_guard_peer' => config('database.connections.mysql')]);
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[0]->id,
        'away_team_id' => $teams[1]->id, 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-01', 'game_time' => '18:00:00']);
    $this->starterReceipt = StartingPitcherForecast::create(['game_id' => $game->id, 'team_id' => $teams[0]->id,
        'season' => 2026, 'side' => 'home', 'forecast_hash' => hash('sha256', 'synthetic-native-starter'),
        'model_version' => 'synthetic-native', 'prediction_source' => 'synthetic-test-only',
        'predicted_pitcher_espn_id' => 'synthetic-pitcher', 'confidence' => 0.6,
        'evidence' => ['z' => 1, 'a' => ['zero' => 0]], 'forecasted_at' => now(), 'known_before_game_start' => true]);
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('starter_guard_peer');
});

it('refuses protected drift committed behind a consistent starter receipt snapshot', function () {
    DB::beginTransaction();
    $stale = StartingPitcherForecast::findOrFail($this->starterReceipt->id);
    DB::connection('starter_guard_peer')->table('mlb_starting_pitcher_forecasts')->where('id', $stale->id)->update(['confidence' => 0.8]);
    $stale->grade = 'correct';
    expect(fn () => $stale->saveQuietly())->toThrow(LogicException::class);
    DB::rollBack();
    $current = StartingPitcherForecast::findOrFail($stale->id);
    expect($current->confidence)->toBe(0.8)->and($current->grade)->toBeNull();
});

it('preserves SQL JSON object order equivalence during mutable confirmation writes', function () {
    $forecast = $this->starterReceipt;
    $before = $forecast->fresh()->evidence;
    $forecast->evidence = ['a' => ['zero' => 0], 'z' => 1];
    $forecast->forceFill(['actual_pitcher_espn_id' => 'synthetic-pitcher', 'grade' => 'correct'])->saveQuietly();
    $forecast->incrementQuietly('rating_difference', 0, ['confirmation_source' => 'synthetic-test-only']);
    expect($forecast->fresh()->evidence)->toBe($before)->and($forecast->fresh()->grade)->toBe('correct');
});
