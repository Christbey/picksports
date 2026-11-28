<?php

use App\Services\Relationships\RelationshipIntegrityAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function relationshipBoundaryDefinition(string $connection, array $foreign = []): array
{
    return ['table' => 'audit_boundary_children', 'connection' => $connection, 'table_exists' => true,
        'columns' => [['name' => 'id'], ['name' => 'parent_id'], ['name' => 'phase']], 'indexes' => [], 'foreign_keys' => $foreign];
}

beforeEach(function () {
    Schema::create('audit_boundary_parents', function ($table) {
        $table->id();
        $table->string('phase')->nullable();
    });
    Schema::create('audit_boundary_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('phase')->nullable();
    });
});

it('keeps same named tables on distinct connections in the audit', function () {
    config(['database.connections.relationship_secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('relationship_secondary')->create('audit_boundary_children', fn ($table) => $table->id());
    DB::table('audit_boundary_children')->insert(['phase' => 'first']);
    DB::connection('relationship_secondary')->table('audit_boundary_children')->insert([['id' => 1], ['id' => 2]]);
    $first = DB::connection()->getName();
    $report = app(RelationshipIntegrityAudit::class)->inspect([
        'first' => relationshipBoundaryDefinition($first), 'second' => relationshipBoundaryDefinition('relationship_secondary'),
    ]);
    expect($report)->toHaveCount(2)
        ->and($report[$first.'::audit_boundary_children']['row_count'])->toBe(1)
        ->and($report['relationship_secondary::audit_boundary_children']['row_count'])->toBe(2);
    DB::purge('relationship_secondary');
});

it('counts null versus known shared lineage as a mismatch in both directions', function () {
    DB::table('audit_boundary_parents')->insert([['id' => 1, 'phase' => null], ['id' => 2, 'phase' => 'pregame']]);
    DB::table('audit_boundary_children')->insert([['parent_id' => 1, 'phase' => 'pregame'], ['parent_id' => 2, 'phase' => null]]);
    $connection = DB::connection()->getName();
    $report = app(RelationshipIntegrityAudit::class)->inspect(['fixture' => relationshipBoundaryDefinition($connection, [
        ['columns' => ['parent_id'], 'foreign_table' => 'audit_boundary_parents', 'foreign_columns' => ['id']],
    ])]);
    expect(collect($report)->first()['foreign_keys'][0]['lineage_mismatch_count'])->toBe(2);
});

it('refuses cross schema parent guessing and marks the check incomplete', function () {
    $report = app(RelationshipIntegrityAudit::class)->inspect(['fixture' => relationshipBoundaryDefinition(DB::connection()->getName(), [
        ['columns' => ['parent_id'], 'foreign_schema' => 'unreviewed_schema', 'foreign_table' => 'audit_boundary_parents', 'foreign_columns' => ['id']],
    ])]);
    $row = collect($report)->first();
    expect($row['complete'])->toBeFalse()
        ->and($row['foreign_keys'][0]['status'])->toBe('unsupported_cross_schema')
        ->and($row['foreign_keys'][0]['orphan_count'])->toBeNull();
});

it('marks oversized tables incomplete rather than reporting sampled counts as exact', function () {
    DB::table('audit_boundary_children')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
    $report = app(RelationshipIntegrityAudit::class)->inspect(['fixture' => relationshipBoundaryDefinition(DB::connection()->getName())], ['max_rows' => 2]);
    $row = collect($report)->first();
    expect($row['complete'])->toBeFalse()->and($row['row_count'])->toBeNull()->and($row['status'])->toBe('row_limit');
});

it('does not scan past table or query budgets and reports missing inventory tables', function () {
    $connection = DB::connection()->getName();
    $first = relationshipBoundaryDefinition($connection);
    $second = [...$first, 'table' => 'audit_boundary_parents'];
    $audit = app(RelationshipIntegrityAudit::class);
    $tables = $audit->inspect(['first' => $first, 'second' => $second], ['max_tables' => 1]);
    expect($tables[$connection.'::audit_boundary_parents']['status'])->toBe('table_limit')
        ->and($tables[$connection.'::audit_boundary_parents']['row_count'])->toBeNull();
    $foreign = [['columns' => ['parent_id'], 'foreign_table' => 'audit_boundary_parents', 'foreign_columns' => ['id']]];
    $limited = $audit->inspect(['first' => relationshipBoundaryDefinition($connection, $foreign)], ['max_queries' => 1]);
    expect(collect($limited)->first()['status'])->toBe('query_limit')
        ->and(collect($limited)->first()['complete'])->toBeFalse();
    $missing = $audit->inspect(['missing' => [...$first, 'table_exists' => false]]);
    expect(collect($missing)->first()['status'])->toBe('table_missing')
        ->and(collect($missing)->first()['row_count'])->toBeNull();
});

it('preflights parent size before running foreign key joins', function () {
    DB::table('audit_boundary_parents')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
    $foreign = [['columns' => ['parent_id'], 'foreign_table' => 'audit_boundary_parents', 'foreign_columns' => ['id']]];
    $report = app(RelationshipIntegrityAudit::class)->inspect([
        'fixture' => relationshipBoundaryDefinition(DB::connection()->getName(), $foreign),
    ], ['max_rows' => 2]);
    expect(collect($report)->first()['status'])->toBe('row_limit')
        ->and(collect($report)->first()['complete'])->toBeFalse()
        ->and(collect($report)->first()['foreign_keys'])->toBe([]);
});

it('does not expose raw SQL errors in an incomplete audit result', function () {
    $foreign = [['columns' => ['parent_id'], 'foreign_table' => 'absent_parent', 'foreign_columns' => ['id']]];
    $report = app(RelationshipIntegrityAudit::class)->inspect([
        'fixture' => relationshipBoundaryDefinition(DB::connection()->getName(), $foreign),
    ]);
    expect(collect($report)->first()['status'])->toBe('query_failed')
        ->and(json_encode($report))->not->toContain('SQLSTATE', 'select', 'absent_parent');
});
