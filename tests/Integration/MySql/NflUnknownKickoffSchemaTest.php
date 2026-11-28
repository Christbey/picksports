<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\User;
use App\Support\NFL\NflGameStart;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')->and(DB::getDatabaseName())->toBe('picksports_test_readiness');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

it('expands only NFL TIME nullability while preserving existing native values and schema attributes', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $midnight = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_time' => '00:00:00']);
    $evening = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_time' => '23:15:42']);
    DB::statement('ALTER TABLE nfl_games MODIFY game_time TIME NOT NULL');
    $column = fn () => (array) DB::selectOne("SELECT COLUMN_TYPE, COLUMN_DEFAULT, EXTRA, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfl_games' AND COLUMN_NAME = 'game_time'");
    $before = $column();
    $rows = DB::table('nfl_games')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_11_26_170000_allow_unknown_nfl_game_times.php');
    $migration->up();
    $after = $column();
    expect($after['IS_NULLABLE'])->toBe('YES')
        ->and(array_diff_key($after, ['IS_NULLABLE' => true]))->toBe(array_diff_key($before, ['IS_NULLABLE' => true]))
        ->and(DB::table('nfl_games')->orderBy('id')->get()->toJson())->toBe($rows)
        ->and($midnight->fresh()->getRawOriginal('game_time'))->toBe('00:00:00')
        ->and($evening->fresh()->getRawOriginal('game_time'))->toBe('23:15:42');
    $unknown = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_time' => null]);
    expect($unknown->fresh()->getRawOriginal('game_time'))->toBeNull();
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Forward-only');
    expect($column())->toBe($after)->and($unknown->fresh()->getRawOriginal('game_time'))->toBeNull();
});

it('keeps unknown imported clocks unavailable while preserving legitimate observed midnight on native MySQL', function () {
    Sanctum::actingAs(User::factory()->create());
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $base = ['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => '2026-10-10', 'game_time' => '00:00:00'];
    $unknown = Game::factory()->create([...$base, 'nflverse_game_id' => 'fixture-unknown', 'odds_data' => ['event_id' => 'nflverse:fixture-unknown', 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]]);
    $midnight = Game::factory()->create([...$base, 'nflverse_game_id' => 'fixture-midnight', 'odds_data' => ['event_id' => 'nflverse:fixture-midnight', 'commence_time' => '2026-10-10T00:00:00Z', 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]]);
    expect(NflGameStart::for($unknown))->toBeNull()->and(NflGameStart::for($midnight)?->toIso8601String())->toBe('2026-10-10T00:00:00+00:00');
    $this->getJson('/api/v2/sports/nfl/games/'.$unknown->id)->assertOk()->assertJsonPath('data.starts_at', null)->assertJsonPath('data.game_time', null);
    $this->getJson('/api/v2/sports/nfl/games/'.$midnight->id)->assertOk()->assertJsonPath('data.starts_at', '2026-10-10T00:00:00+00:00');
    expect($unknown->fresh()->getRawOriginal('game_time'))->toBe('00:00:00');
});

it('preserves native TIME attributes and fractional row bytes when admitting unknown clocks', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_time' => '12:34:56']);
    DB::statement("ALTER TABLE nfl_games MODIFY game_time TIME(6) NOT NULL DEFAULT '12:00:00.123456' COMMENT 'Synthetic fractional kickoff'");
    DB::table('nfl_games')->where('id', $game->id)->update(['game_time' => '23:15:42.654321']);
    $column = fn () => (array) DB::selectOne("SELECT COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfl_games' AND COLUMN_NAME = 'game_time'");
    $before = $column();
    $rows = DB::table('nfl_games')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_11_26_170000_allow_unknown_nfl_game_times.php');
    $migration->up();
    $after = $column();
    expect($after['IS_NULLABLE'])->toBe('YES')
        ->and(array_diff_key($after, ['IS_NULLABLE' => true]))->toBe(array_diff_key($before, ['IS_NULLABLE' => true]))
        ->and(DB::table('nfl_games')->orderBy('id')->get()->toJson())->toBe($rows);
    $migration->up();
    expect($column())->toBe($after)->and(DB::table('nfl_games')->orderBy('id')->get()->toJson())->toBe($rows);
});

it('refuses an unexpected native clock column type before changing schema or retained data', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_time' => '12:34:56']);
    DB::statement("ALTER TABLE nfl_games MODIFY game_time VARCHAR(30) NOT NULL DEFAULT 'unverified'");
    $column = fn () => (array) DB::selectOne("SELECT COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfl_games' AND COLUMN_NAME = 'game_time'");
    $before = $column();
    $rows = DB::table('nfl_games')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_11_26_170000_allow_unknown_nfl_game_times.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unexpected NFL game_time schema');
    expect($column())->toBe($before)->and(DB::table('nfl_games')->orderBy('id')->get()->toJson())->toBe($rows);
});
