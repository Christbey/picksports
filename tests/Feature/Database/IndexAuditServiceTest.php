<?php

use App\Services\Database\IndexAuditService;

it('identifies only advisory non-unique index candidates', function () {
    $findings = app(IndexAuditService::class)->analyze([
        [
            'table' => 'games',
            'name' => 'games_status_idx',
            'unique' => false,
            'columns' => [['name' => 'status', 'prefix' => null]],
            'cardinality' => 2,
        ],
        [
            'table' => 'games',
            'name' => 'games_status_date_idx',
            'unique' => false,
            'columns' => [
                ['name' => 'status', 'prefix' => null],
                ['name' => 'game_date', 'prefix' => null],
            ],
            'cardinality' => 500,
        ],
        [
            'table' => 'games',
            'name' => 'games_provider_unique',
            'unique' => true,
            'columns' => [['name' => 'provider_id', 'prefix' => null]],
            'cardinality' => 500,
        ],
        [
            'table' => 'games',
            'name' => 'games_provider_duplicate_idx',
            'unique' => false,
            'columns' => [['name' => 'provider_id', 'prefix' => null]],
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
            'unique' => false,
            'columns' => [['name' => 'payload_hash', 'prefix' => 8]],
            'cardinality' => 100,
        ],
        [
            'table' => 'payloads',
            'name' => 'payload_hash_16',
            'unique' => false,
            'columns' => [['name' => 'payload_hash', 'prefix' => 16]],
            'cardinality' => 100,
        ],
    ]);

    expect($findings)->toBe([]);
});
