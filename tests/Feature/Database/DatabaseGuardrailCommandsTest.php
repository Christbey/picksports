<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('database fingerprint captures deterministic schema migration constraint and row count identity', function () {
    $firstExitCode = Artisan::call('db:fingerprint', [
        '--connection' => config('database.default'),
        '--exact-counts' => true,
    ]);
    $first = json_decode(Artisan::output(), true);

    $secondExitCode = Artisan::call('db:fingerprint', [
        '--connection' => config('database.default'),
        '--exact-counts' => true,
    ]);
    $second = json_decode(Artisan::output(), true);

    expect($firstExitCode)->toBe(0)->and($secondExitCode)->toBe(0);
    $users = collect($first['tables'])->firstWhere('name', 'users');

    expect($firstExitCode)->toBe(0)
        ->and($secondExitCode)->toBe(0)
        ->and($first['format_version'])->toBe(1)
        ->and($first['driver'])->toBe(config('database.connections.'.config('database.default').'.driver'))
        ->and($first['row_count_mode'])->toBe('exact')
        ->and($first['migration_state']['pending'])->toBe([])
        ->and($first['migrations'])->not->toBeEmpty()
        ->and($first['tables'])->not->toBeEmpty()
        ->and($users)->toBeArray()
        ->and($users)->toHaveKeys(['columns', 'indexes', 'foreign_keys', 'row_count', 'schema_hash'])
        ->and($first['schema_hash'])->toHaveLength(64)
        ->and($first['migration_hash'])->toHaveLength(64)
        ->and($first['data_hash'])->toHaveLength(64)
        ->and($first['fingerprint'])->toHaveLength(64)
        ->and($second['fingerprint'])->toBe($first['fingerprint'])
        ->and($second['data_hash'])->toBe($first['data_hash']);
});

test('schema health verifies an exact baseline and reports identity mismatches', function () {
    $path = storage_path('framework/testing/database-fingerprint.json');
    File::delete($path);

    $fingerprintExitCode = Artisan::call('db:fingerprint', [
        '--connection' => config('database.default'),
        '--output' => $path,
        '--exact-counts' => true,
        '--pretty' => true,
    ]);

    $healthyExitCode = Artisan::call('db:schema-health', [
        '--connection' => config('database.default'),
        '--against' => $path,
        '--check-row-counts' => true,
        '--json' => true,
    ]);
    $healthy = json_decode(Artisan::output(), true);

    $wrongDatabaseExitCode = Artisan::call('db:schema-health', [
        '--connection' => config('database.default'),
        '--expected-database' => 'definitely-not-the-test-database',
        '--json' => true,
    ]);
    $wrongDatabase = json_decode(Artisan::output(), true);

    $baseline = json_decode(File::get($path), true);
    $baseline['schema_hash'] = str_repeat('0', 64);
    File::put($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $wrongSchemaExitCode = Artisan::call('db:schema-health', [
        '--connection' => config('database.default'),
        '--against' => $path,
        '--json' => true,
    ]);
    $wrongSchema = json_decode(Artisan::output(), true);

    $missingBaselineExitCode = Artisan::call('db:schema-health', [
        '--connection' => config('database.default'),
        '--check-row-counts' => true,
    ]);
    $missingBaselineOutput = Artisan::output();

    File::delete($path);

    expect($fingerprintExitCode)->toBe(0)
        ->and($healthyExitCode)->toBe(0)
        ->and($healthy['healthy'])->toBeTrue()
        ->and($healthy['server_version'])->toBeString()->not->toBeEmpty()
        ->and(collect($healthy['checks'])->pluck('status')->unique()->all())->toBe(['pass'])
        ->and($wrongDatabaseExitCode)->toBe(1)
        ->and($wrongDatabase['healthy'])->toBeFalse()
        ->and(collect($wrongDatabase['checks'])->firstWhere('name', 'database_name')['status'])->toBe('fail')
        ->and($wrongSchemaExitCode)->toBe(1)
        ->and($wrongSchema['healthy'])->toBeFalse()
        ->and(collect($wrongSchema['checks'])->firstWhere('name', 'schema')['status'])->toBe('fail')
        ->and($missingBaselineExitCode)->toBe(1)
        ->and($missingBaselineOutput)->toContain('requires an --against baseline');
});

it('fails schema health when the applied migration ledger contains missing source history', function () {
    DB::table('migrations')->insert([
        'migration' => '2025_01_01_000000_missing_history', 'batch' => 1,
    ]);
    $exit = Artisan::call('db:schema-health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)->and($report['healthy'])->toBeFalse()
        ->and(collect($report['checks'])->firstWhere('name', 'unknown_migrations')['status'])->toBe('fail');
});
