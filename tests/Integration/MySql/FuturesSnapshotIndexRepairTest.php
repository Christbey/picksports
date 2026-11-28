<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->assertSame('mysql', DB::connection()->getDriverName());
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $indexes = Schema::getIndexes('sports_futures_odds_snapshots');
    $drop = [];
    foreach ($indexes as $index) {
        if ($index['primary'] || str_ends_with($index['name'], '_foreign')) {
            continue;
        }
        if ($index['columns'] === ['sport', 'season', 'captured_at']) {
            continue;
        }
        $drop[] = 'DROP INDEX `'.$index['name'].'`';
    }
    $drop[] = 'RENAME INDEX `sfos_sport_season_captured_idx` TO `sports_futures_odds_snapshots_sport_season_captured_at_index`';
    DB::statement('ALTER TABLE sports_futures_odds_snapshots '.implode(', ', $drop));
    $this->repair = require database_path('migrations/2026_11_25_020000_reconcile_futures_snapshot_indexes.php');
    $this->snapshot = [
        'snapshot_key' => str_repeat('a', 40), 'row_key' => str_repeat('b', 40),
        'sport' => 'nfl', 'season' => 2026, 'odds_api_sport_key' => 'nfl',
        'bookmaker' => 'fixture', 'market_key' => 'win_total', 'outcome_name' => 'Fixture team',
        'captured_at' => now()->startOfSecond()->toDateTimeString(), 'price' => 150,
    ];
});

function repairIndexShape(): array
{
    return collect(Schema::getIndexes('sports_futures_odds_snapshots'))->sortBy('name')->values()->all();
}

function repairSnapshotRows(): array
{
    return DB::table('sports_futures_odds_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('aborts duplicate preflight before any DDL and leaves every row and index unchanged', function () {
    DB::table('sports_futures_odds_snapshots')->insert([$this->snapshot, $this->snapshot]);
    $indexes = repairIndexShape();
    $rows = repairSnapshotRows();
    DB::enableQueryLog();
    expect(fn () => $this->repair->up())->toThrow(RuntimeException::class, 'Duplicate futures snapshot keys');
    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();
    expect(repairIndexShape())->toBe($indexes)
        ->and(repairSnapshotRows())->toBe($rows)
        ->and(array_any($queries, fn ($sql) => str_contains($sql, 'MAX_EXECUTION_TIME(5000)')))->toBeTrue()
        ->and(array_any($queries, fn ($sql) => preg_match('/\A\s*(ALTER|CREATE|DROP)\b/i', $sql) === 1))->toBeFalse();
});

it('restores required indexes atomically while preserving production aliases and snapshot rows', function () {
    DB::table('sports_futures_odds_snapshots')->insert([$this->snapshot, [...$this->snapshot, 'snapshot_key' => str_repeat('c', 40)]]);
    $rows = repairSnapshotRows();
    $lockWait = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
    DB::enableQueryLog();
    $this->repair->up();
    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();
    $ddl = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'ALTER TABLE')));
    expect($ddl)->toHaveCount(1)
        ->and($ddl[0])->toContain('ALGORITHM=INPLACE, LOCK=NONE')
        ->and(repairSnapshotRows())->toBe($rows)
        ->and((int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds)->toBe($lockWait);
    $indexes = repairIndexShape();
    expect(collect($indexes)->where('columns', ['snapshot_key'])->where('unique', true)->count())->toBe(1)
        ->and(collect($indexes)->where('columns', ['sport', 'season', 'captured_at'])->count())->toBe(1)
        ->and(Schema::hasIndex('sports_futures_odds_snapshots', 'sports_futures_odds_snapshots_sport_season_captured_at_index'))->toBeTrue()
        ->and(Schema::hasIndex('sports_futures_odds_snapshots', 'sfos_sport_season_captured_idx'))->toBeFalse()
        ->and(Schema::hasIndex('sports_futures_odds_snapshots', 'sfos_market_captured_idx'))->toBeTrue();
    $this->repair->up();
    expect(repairIndexShape())->toBe($indexes)->and(repairSnapshotRows())->toBe($rows);
    DB::table('sports_futures_odds_snapshots')->upsert([[...$this->snapshot, 'price' => 175]], ['snapshot_key'], ['price']);
    expect(DB::table('sports_futures_odds_snapshots')->count())->toBe(2)
        ->and(DB::table('sports_futures_odds_snapshots')->where('snapshot_key', $this->snapshot['snapshot_key'])->value('price'))->toBe(175);
});

it('accepts an equivalent full unique index under another name without duplicating it', function () {
    DB::table('sports_futures_odds_snapshots')->insert($this->snapshot);
    DB::statement('ALTER TABLE sports_futures_odds_snapshots ADD UNIQUE INDEX retained_snapshot_unique (snapshot_key)');
    $this->repair->up();
    expect(Schema::hasIndex('sports_futures_odds_snapshots', 'retained_snapshot_unique'))->toBeTrue()
        ->and(Schema::hasIndex('sports_futures_odds_snapshots', 'sports_futures_odds_snapshots_snapshot_key_unique'))->toBeFalse()
        ->and(collect(repairIndexShape())->where('columns', ['snapshot_key'])->where('unique', true)->count())->toBe(1);
});

it('fails closed on a conflicting required index name without changing indexes', function () {
    DB::statement('ALTER TABLE sports_futures_odds_snapshots ADD INDEX sports_futures_odds_snapshots_snapshot_key_unique (row_key)');
    $before = repairIndexShape();
    expect(fn () => $this->repair->up())->toThrow(RuntimeException::class, 'name conflicts')
        ->and(repairIndexShape())->toBe($before);
});

it('propagates a preflight timeout before any index statement or row modification', function () {
    DB::table('sports_futures_odds_snapshots')->insert($this->snapshot);
    $indexes = repairIndexShape();
    $rows = repairSnapshotRows();
    $connection = DB::connection();
    $beforeExecution = [];
    $connection->beforeExecuting(function (string $sql) use (&$beforeExecution): void {
        $beforeExecution[] = $sql;
        if (str_contains($sql, 'MAX_EXECUTION_TIME(5000)')) {
            throw new RuntimeException('Injected preflight server timeout.');
        }
    });
    try {
        expect(fn () => $this->repair->up())->toThrow(RuntimeException::class, 'preflight server timeout')
            ->and(array_any($beforeExecution, fn ($sql) => preg_match('/\A\s*(ALTER|CREATE|DROP)\b/i', $sql) === 1))->toBeFalse();
    } finally {
        DB::purge();
    }
    expect(repairIndexShape())->toBe($indexes)->and(repairSnapshotRows())->toBe($rows);
});

it('adds no partial indexes if a concurrent duplicate appears after preflight', function () {
    DB::table('sports_futures_odds_snapshots')->insert($this->snapshot);
    $indexes = repairIndexShape();
    $connection = DB::connection();
    $inserted = false;
    $snapshot = $this->snapshot;
    $connection->beforeExecuting(function (string $sql) use (&$inserted, $connection, $snapshot): void {
        if (! $inserted && str_starts_with($sql, 'ALTER TABLE')) {
            $inserted = true;
            // Simulate another committed writer between the preflight and build.
            $connection->table('sports_futures_odds_snapshots')->insert($snapshot);
        }
    });
    try {
        expect(fn () => $this->repair->up())->toThrow(QueryException::class);
    } finally {
        DB::purge();
    }
    expect($inserted)->toBeTrue()
        ->and(repairIndexShape())->toBe($indexes)
        ->and(DB::table('sports_futures_odds_snapshots')->count())->toBe(2);
});
