<?php

use App\Services\Database\IndexAuditService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;

it('identifies only advisory non-unique index candidates', function () {
    $findings = app(IndexAuditService::class)->analyze([
        [
            'table' => 'games',
            'name' => 'games_status_idx',
            'unique' => false, 'type' => 'BTREE', 'visible' => true,
            'columns' => [['name' => 'status', 'prefix' => null, 'direction' => 'A', 'expression' => null]],
            'cardinality' => 2,
        ],
        [
            'table' => 'games',
            'name' => 'games_status_date_idx',
            'unique' => false, 'type' => 'BTREE', 'visible' => true,
            'columns' => [
                ['name' => 'status', 'prefix' => null, 'direction' => 'A', 'expression' => null],
                ['name' => 'game_date', 'prefix' => null, 'direction' => 'A', 'expression' => null],
            ],
            'cardinality' => 500,
        ],
        [
            'table' => 'games',
            'name' => 'games_provider_unique',
            'unique' => true, 'type' => 'BTREE', 'visible' => true,
            'columns' => [['name' => 'provider_id', 'prefix' => null, 'direction' => 'A', 'expression' => null]],
            'cardinality' => 500,
        ],
        [
            'table' => 'games',
            'name' => 'games_provider_duplicate_idx',
            'unique' => false, 'type' => 'BTREE', 'visible' => true,
            'columns' => [['name' => 'provider_id', 'prefix' => null, 'direction' => 'A', 'expression' => null]],
            'cardinality' => 500,
        ],
    ]);

    expect($findings)->toContainEqual([
        'table' => 'games',
        'index' => 'games_status_idx',
        'kind' => 'left_prefix',
        'covered_by' => 'games_status_date_idx',
        'columns' => ['status'],
        'message' => 'Non-unique index is a left-prefix of a wider index.',
    ])->toContainEqual([
        'table' => 'games',
        'index' => 'games_status_idx',
        'kind' => 'low_cardinality',
        'covered_by' => null,
        'columns' => ['status'],
        'message' => 'Single-column index has estimated cardinality <= 2; validate real EXPLAIN plans before retaining or removing it.',
    ])->toContainEqual([
        'table' => 'games',
        'index' => 'games_provider_duplicate_idx',
        'kind' => 'duplicate',
        'covered_by' => 'games_provider_unique',
        'columns' => ['provider_id'],
        'message' => 'Non-unique index has the same column definition as another index.',
    ]);

    expect(collect($findings)->pluck('index'))->not->toContain('games_provider_unique');
});

it('does not treat different prefix lengths as duplicate indexes', function () {
    $findings = app(IndexAuditService::class)->analyze([
        [
            'table' => 'payloads',
            'name' => 'payload_hash_8',
            'unique' => false, 'type' => 'BTREE', 'visible' => true,
            'columns' => [['name' => 'payload_hash', 'prefix' => 8, 'direction' => 'A', 'expression' => null]],
            'cardinality' => 100,
        ],
        [
            'table' => 'payloads',
            'name' => 'payload_hash_16',
            'unique' => false, 'type' => 'BTREE', 'visible' => true,
            'columns' => [['name' => 'payload_hash', 'prefix' => 16, 'direction' => 'A', 'expression' => null]],
            'cardinality' => 100,
        ],
    ]);

    expect($findings)->toBe([]);
});

it('does not call indexes redundant when mysql usability or definitions differ', function (string $difference) {
    $manager = Mockery::mock(DatabaseManager::class);
    $connection = Mockery::mock(MySqlConnection::class);
    $manager->shouldReceive('connection')->with(null)->andReturn($connection);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getDatabaseName')->andReturn('synthetic');
    $rows = [];
    foreach (['candidate', 'covering'] as $index) {
        foreach (['home', 'away'] as $position => $column) {
            $covering = $index === 'covering';
            $rows[] = (object) [
                'table_name' => 'games', 'index_name' => $index, 'non_unique' => 1,
                'sequence' => $position + 1, 'column_name' => $difference === 'expression' ? null : $column,
                'sub_part' => null, 'cardinality' => 100,
                'index_type' => $covering && $difference === 'type' ? 'FULLTEXT' : 'BTREE',
                'is_visible' => $covering && $difference === 'visibility' ? 'NO' : 'YES',
                'direction' => $covering && $position === 1 && $difference === 'direction' ? 'D' : 'A',
                'expression' => $difference === 'expression' ? ($covering ? '`away` + 1' : '`home` + 1') : null,
            ];
        }
    }
    $connection->shouldReceive('select')->once()->andReturn($rows);
    $findings = (new IndexAuditService($manager))->inspect();
    expect(collect($findings)->whereIn('kind', ['duplicate', 'left_prefix'])->all())->toBe([]);
})->with(['type', 'visibility', 'direction', 'expression']);

it('retains one canonical duplicate instead of suggesting mutually covered removals', function () {
    $index = ['table' => 'games', 'type' => 'BTREE', 'visible' => true, 'unique' => false,
        'columns' => [['name' => 'home', 'prefix' => null, 'direction' => 'A', 'expression' => null]],
        'cardinality' => 100];
    $findings = app(IndexAuditService::class)->analyze([
        [...$index, 'name' => 'a_keep'], [...$index, 'name' => 'b_duplicate'],
    ]);
    expect(array_column($findings, 'index'))->toBe(['b_duplicate']);
});

it('reports unverified definitions as not assessed rather than evidence of redundancy', function () {
    $index = ['table' => 'games', 'unique' => false,
        'columns' => [['name' => 'home', 'prefix' => null]], 'cardinality' => 100];
    $findings = app(IndexAuditService::class)->analyze([
        [...$index, 'name' => 'a_unknown'], [...$index, 'name' => 'b_unknown'],
    ]);
    expect(array_column($findings, 'kind'))->toBe(['not_assessed', 'not_assessed']);
});
