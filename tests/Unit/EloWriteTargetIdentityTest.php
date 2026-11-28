<?php

use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

uses(TestCase::class);

it('binds SQLite approvals to the canonical write file rather than aliases or replicas', function () {
    $directory = sys_get_temp_dir().'/elo-target-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $source = $directory.'/source.sqlite';
        $clone = $directory.'/clone.sqlite';
        $pdo = new PDO('sqlite:'.$source);
        $pdo->exec('CREATE TABLE identity_probe (id INTEGER)');
        copy($source, $clone);
        $first = new SQLiteConnection($pdo, $source, '', ['driver' => 'sqlite', 'name' => 'first']);
        $alias = new SQLiteConnection(new PDO('sqlite:'.$source), $source, '', ['driver' => 'sqlite', 'name' => 'alias']);
        $other = new SQLiteConnection(new PDO('sqlite:'.$clone), $clone, '', ['driver' => 'sqlite', 'name' => 'clone']);
        $first->setReadPdo($other->getPdo());
        $identity = app(EloWriteTargetIdentity::class);
        expect(hash_file('sha256', $source))->toBe(hash_file('sha256', $clone))
            ->and($identity->describe($first))->toBe($identity->describe($alias))
            ->and($identity->describe($first)['fingerprint'])->not->toBe($identity->describe($other)['fingerprint'])
            ->and($identity->describe($first)['portable_approval'])->toBeTrue();
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('keeps memory approval identities local to the same PDO and process', function () {
    $pdo = new PDO('sqlite::memory:');
    $first = new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'name' => 'one']);
    $alias = new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'name' => 'two']);
    $other = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $identity = app(EloWriteTargetIdentity::class);
    $description = $identity->describe($first);
    expect($description)->toBe($identity->describe($alias))
        ->and($description['fingerprint'])->not->toBe($identity->describe($other)['fingerprint'])
        ->and($description['scope'])->toBe('process_local_memory')
        ->and($description['portable_approval'])->toBeFalse();
    config(['app.env' => 'another-environment']);
    expect($identity->describe($first)['fingerprint'])->not->toBe($description['fingerprint']);
});

it('uses authoritative MySQL write metadata and excludes connection aliases', function () {
    $make = function (string $alias, string $database, string $uuid = '12345678-1234-1234-1234-123456789abc') {
        $connection = Mockery::mock(MySqlConnection::class)->makePartial();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getNameWithReadWriteType')->andReturn($alias);
        $connection->shouldReceive('selectOne')->with('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name', [], false)
            ->andReturn((object) ['server_uuid' => $uuid, 'database_name' => $database]);

        return $connection;
    };
    $identity = app(EloWriteTargetIdentity::class);
    $first = $identity->describe($make('primary', 'fixture'));
    expect($first)->toBe($identity->describe($make('alias', 'fixture')))
        ->and($first['fingerprint'])->not->toBe($identity->describe($make('clone', 'fixture_clone'))['fingerprint'])
        ->and($first['fingerprint'])->not->toBe($identity->describe($make('clone', 'fixture', 'abcdefab-1234-1234-1234-123456789abc'))['fingerprint']);
});

it('fails closed when authoritative identity is missing or explicitly read only', function (bool $readOnly) {
    $connection = Mockery::mock(MySqlConnection::class)->makePartial();
    $connection->shouldReceive('getNameWithReadWriteType')->andReturn($readOnly ? 'fixture::read' : 'fixture');
    if (! $readOnly) {
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('selectOne')->andReturn((object) ['server_uuid' => null, 'database_name' => 'fixture']);
    }
    expect(fn () => app(EloWriteTargetIdentity::class)->describe($connection))
        ->toThrow(RuntimeException::class, 'Authoritative Elo write-target identity is unavailable.');
})->with([true, false]);
