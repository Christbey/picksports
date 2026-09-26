<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\NflOddsHistory;
use App\Services\NFL\NflProSignalLayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('nfl', 'predictions');

function nflHistoryGame(array $attributes = []): Game
{
    return Game::factory()->create(array_merge([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
    ], $attributes));
}

function createNflHistorySnapshot(Game $game, int $index, ?string $capturedAt = null): GameOddsSnapshot
{
    return GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'captured_at' => $capturedAt ?? now()->startOfDay()->addMinutes($index),
        'payload_hash' => (string) $index,
        'odds_data' => [
            'home_team' => 'Home',
            'bookmakers' => [['markets' => [
                ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -$index]]],
                ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 43.5]]],
            ]]],
        ],
    ]);
}

it('matches ordered history while hydrating at most three snapshots once', function (int $count) {
    $game = nflHistoryGame();
    for ($index = 0; $index < $count; $index++) {
        createNflHistorySnapshot($game, $index);
    }
    $expected = GameOddsSnapshot::query()->where('game_id', $game->id)->orderBy('captured_at')->orderBy('id')->get();
    $retrieved = 0;
    GameOddsSnapshot::retrieved(function () use (&$retrieved) {
        $retrieved++;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();

    $history = new NflOddsHistory($game);
    // Repeat consumers in different orders to prove shared memoization.
    for ($consumer = 0; $consumer < 3; $consumer++) {
        expect($history->last()?->id)->toBe($expected->last()?->id)
            ->and($history->first()?->odds_data)->toBe($expected->first()?->odds_data)
            ->and($history->middle()?->id)->toBe($count >= 3 ? $expected[intdiv($count, 2)]->id : null)
            ->and($history->count())->toBe($count);
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($retrieved)->toBe(min(3, $count))
        ->and($queries)->toHaveCount(1 + min(3, $count));
    foreach (array_slice($queries, 1) as $query) {
        expect(strtolower($query['query']))->toContain('limit 1')->not->toContain('select *');
    }
})->with([0, 1, 2, 3, 4, 5, 1000]);

it('breaks captured-at ties by snapshot id and isolates game and sport', function () {
    $game = nflHistoryGame();
    $timestamp = '2026-09-20 10:00:00';
    $first = createNflHistorySnapshot($game, 1, $timestamp);
    $middle = createNflHistorySnapshot($game, 2, $timestamp);
    $last = createNflHistorySnapshot($game, 3, $timestamp);
    createNflHistorySnapshot($game, 4, $timestamp)->update(['sport' => 'cfb']);
    createNflHistorySnapshot($game, 5, $timestamp)->update(['game_table' => 'cfb_games']);
    createNflHistorySnapshot(nflHistoryGame(), 6, $timestamp);

    $history = new NflOddsHistory($game);
    expect($history->count())->toBe(3)
        ->and($history->first()?->id)->toBe($first->id)
        ->and($history->middle()?->id)->toBe($middle->id)
        ->and($history->last()?->id)->toBe($last->id);
});

it('does not reuse the previous prediction history after a quote refresh', function () {
    $game = nflHistoryGame();
    $first = createNflHistorySnapshot($game, 1);
    $middle = createNflHistorySnapshot($game, 2);
    $last = createNflHistorySnapshot($game, 3);
    $firstPrediction = new NflOddsHistory($game);
    expect($firstPrediction->count())->toBe(3);
    $newQuote = createNflHistorySnapshot($game, 4);
    // A late-ingested older quote must not shift the pinned first/middle either.
    createNflHistorySnapshot($game, 0);

    expect($firstPrediction->first()?->id)->toBe($first->id)
        ->and($firstPrediction->middle()?->id)->toBe($middle->id)
        ->and($firstPrediction->last()?->id)->toBe($last->id)
        ->and($firstPrediction->count())->toBe(3);

    $nextPrediction = new NflOddsHistory($game);
    expect($nextPrediction->count())->toBe(5)
        ->and($nextPrediction->last()?->id)->toBe($newQuote->id);
});

it('shares history with signal entry fallbacks and movement without rereading endpoints', function () {
    $game = nflHistoryGame(['odds_data' => null]);
    for ($index = 1; $index <= 4; $index++) {
        createNflHistorySnapshot($game, $index);
    }
    $history = new NflOddsHistory($game);
    $history->first();
    $history->last();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $layer = app(NflProSignalLayer::class)->build($game, [], [], 5.0, 44.0, 0.65, $history);

    $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'game_odds_snapshots'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)
        ->and(data_get($layer, 'market_context.market_spread'))->toBe(1.0)
        ->and(data_get($layer, 'market_context.market_total'))->toBe(43.5)
        ->and(data_get($layer, 'market_movement.open_spread'))->toBe(1.0)
        ->and(data_get($layer, 'market_movement.last_snapshot_spread'))->toBe(4.0)
        ->and(data_get($layer, 'market_movement.snapshot_span_minutes'))->toEqual(3);
});

it('adds and rolls back only its ordered history index', function () {
    $migration = require database_path('migrations/2026_09_21_154200_add_ordered_game_odds_history_index.php');
    $index = 'game_odds_snapshots_ordered_game_lookup';
    expect(Schema::hasIndex('game_odds_snapshots', $index))->toBeTrue();

    $migration->down();
    expect(Schema::hasIndex('game_odds_snapshots', $index))->toBeFalse()
        ->and(Schema::hasIndex('game_odds_snapshots', 'game_odds_snapshots_game_lookup'))->toBeTrue();

    $migration->up();
    $definition = collect(Schema::getIndexes('game_odds_snapshots'))->firstWhere('name', $index);
    expect($definition['columns'])->toBe(['sport', 'game_table', 'game_id', 'captured_at', 'id']);
});
