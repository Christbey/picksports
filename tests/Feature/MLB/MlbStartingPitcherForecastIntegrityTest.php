<?php

use App\Models\MLB\Game;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\DB;

function immutableStarterForecast(): StartingPitcherForecast
{
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->withSyntheticWallStart('UTC')->create(['status' => 'STATUS_SCHEDULED',
        'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'game_date' => '2026-07-01', 'game_time' => '18:00:00']);

    return StartingPitcherForecast::create(['game_id' => $game->id, 'team_id' => $game->home_team_id,
        'season' => $game->season, 'side' => 'home', 'forecast_hash' => hash('sha256', 'synthetic-guard-'.$game->id),
        'model_version' => 'synthetic-immutable-v1', 'prediction_source' => 'synthetic-test-only',
        'predicted_pitcher_espn_id' => 'synthetic-starter', 'confidence' => 0.6, 'predicted_pitcher_rating' => 1512.5,
        'evidence' => ['z' => 1, 'a' => ['observed' => 0], 'sequence' => [1, 2]], 'forecasted_at' => now(),
        'game_start_at' => now()->addDay(), 'known_before_game_start' => true, 'rating_difference' => 0]);
}

it('refuses quiet mutation of protected starter forecast evidence', function () {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    $forecast->confidence = 0.9;
    expect(fn () => $forecast->saveQuietly())->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
});

it('compares protected starter fields to persisted state when a stale object grades', function () {
    $forecast = immutableStarterForecast();
    $forecast->newQuery()->whereKey($forecast->id)->update(['confidence' => 0.8]);
    $before = $forecast->fresh()->getAttributes();
    $forecast->grade = 'correct';
    expect(fn () => $forecast->saveQuietly())->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
});

it('refuses numeric APIs that mutate protected starter forecast fields', function (string $method, string $column) {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    expect(fn () => $forecast->{$method}($column, 0.1))->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
})->with([['increment', 'confidence'], ['incrementQuietly', 'confidence'],
    ['decrement', 'predicted_pitcher_rating'], ['decrementQuietly', 'predicted_pitcher_rating']]);

it('refuses protected extra fields on an otherwise mutable numeric operation', function () {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    expect(fn () => $forecast->incrementQuietly('rating_difference', 1, ['confidence' => 0.9]))->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
});

it('refuses numeric operations without a persisted forecast identity', function () {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    expect(fn () => (new StartingPitcherForecast)->incrementQuietly('rating_difference', 1))->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
});

it('retains starter forecast history through ordinary and quiet deletion APIs', function (string $method) {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    expect(fn () => $forecast->{$method}())->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
})->with(['delete', 'deleteQuietly']);

it('refuses starter forecast primary key reassignment through a quiet save', function () {
    $forecast = immutableStarterForecast();
    $id = $forecast->id;
    $before = $forecast->fresh()->getAttributes();
    $forecast->id = $id + 1000;
    expect(fn () => $forecast->saveQuietly())->toThrow(LogicException::class);
    expect(StartingPitcherForecast::findOrFail($id)->getAttributes())->toBe($before);
});

it('preserves mutable starter confirmation and grade writes', function () {
    $forecast = immutableStarterForecast();
    $forecast->forceFill(['actual_pitcher_espn_id' => 'synthetic-starter', 'actual_pitcher_rating' => 1501,
        'confirmation_source' => 'synthetic-test-only', 'confirmed_at' => now(), 'is_correct' => true,
        'grade' => 'correct', 'graded_at' => now()])->save();
    expect($forecast->fresh()->grade)->toBe('correct')->and($forecast->fresh()->confidence)->toBe(0.6);
});

it('preserves mutable quiet and numeric starter grading operations', function () {
    $forecast = immutableStarterForecast();
    $forecast->grade = 'incorrect';
    $forecast->saveQuietly();
    $forecast->incrementQuietly('rating_difference', 0.25);
    expect($forecast->fresh()->grade)->toBe('incorrect')->and($forecast->fresh()->rating_difference)->toBe(0.25);
});

it('treats JSON object member order as equivalent while retaining its observed values', function () {
    $forecast = immutableStarterForecast();
    $forecast->evidence = ['sequence' => [1, 2], 'a' => ['observed' => 0], 'z' => 1];
    $forecast->grade = 'correct';
    $forecast->saveQuietly();
    expect($forecast->fresh()->evidence)->toMatchArray(['a' => ['observed' => 0], 'z' => 1]);
});

it('keeps colliding starter receipt identities on the model resolved connection', function () {
    $forecast = immutableStarterForecast();
    $default = $forecast->getConnection();
    if ($default->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Independent SQLite target fixture; native current-row locking is separately tested.');
    }
    config(['database.connections.starter_guard_target' => ['driver' => 'sqlite', 'database' => ':memory:',
        'prefix' => '', 'foreign_key_constraints' => false]]);
    $target = DB::connection('starter_guard_target');
    try {
        $schema = $default->table('sqlite_master')->where('type', 'table')->where('name', $forecast->getTable())->value('sql');
        $target->statement($schema);
        $attributes = $forecast->fresh()->getAttributes();
        $attributes['confidence'] = 0.8;
        $target->table($forecast->getTable())->insert($attributes);
        $other = StartingPitcherForecast::on('starter_guard_target')->findOrFail($forecast->id);
        $other->grade = 'correct';
        $other->saveQuietly();
        expect($other->fresh()->grade)->toBe('correct')->and($other->fresh()->confidence)->toBe(0.8)
            ->and($forecast->fresh()->grade)->toBeNull()->and($forecast->fresh()->confidence)->toBe(0.6);
    } finally {
        DB::purge('starter_guard_target');
    }
});

it('retains exact evidence string values and list order through quiet starter saves', function (array $evidence) {
    $forecast = immutableStarterForecast();
    $before = $forecast->fresh()->getAttributes();
    $forecast->evidence = $evidence;
    expect(fn () => $forecast->saveQuietly())->toThrow(LogicException::class);
    expect($forecast->fresh()->getAttributes())->toBe($before);
})->with([
    [['z' => 1, 'a' => ['observed' => '0'], 'sequence' => [1, 2]]],
    [['z' => 1, 'a' => ['observed' => 0], 'sequence' => [2, 1]]],
]);
