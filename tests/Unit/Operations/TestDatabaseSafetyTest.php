<?php

use Tests\Support\TestDatabaseSafety;

function syntheticTestDatabaseConfiguration(array $connection, string $environment = 'testing'): array
{
    return ['app' => ['env' => $environment], 'database' => ['default' => 'selected', 'connections' => ['selected' => $connection]]];
}

it('accepts only the intended in-memory SQLite default without opt-in', function () {
    TestDatabaseSafety::assertSafe(syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:']), false);

    expect(true)->toBeTrue();
});

it('rejects inherited or cached unsafe test targets', function (array $configuration, bool $cached, ?string $optIn = null) {
    expect(fn () => TestDatabaseSafety::assertSafe($configuration, $cached, $optIn))->toThrow(RuntimeException::class);
})->with([
    'production environment' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:'], 'production'), false],
    'cached testing configuration' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:']), true],
    'persistent sqlite file' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => '/synthetic/production.sqlite']), false],
    'SQLite read override' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'read' => ['database' => '/synthetic/read.sqlite']]), false],
    'SQLite write override' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'write' => ['database' => '/synthetic/write.sqlite']]), false],
    'SQLite URL read/write overrides' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///:memory:?read[database]=/synthetic/read.sqlite&write[database]=/synthetic/write.sqlite']), false],
    'null read override' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'read' => null]), false],
    'empty write override' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'write' => []]), false],
    'URL overrides safe defaults' => [syntheticTestDatabaseConfiguration(['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'mysql://user:secret@remote.invalid/production']), false],
    'no MySQL opt-in' => [syntheticTestDatabaseConfiguration(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'picksports_test_readiness', 'username' => 'picksports_test']), false],
    'remote server even with opt-in' => [syntheticTestDatabaseConfiguration(['driver' => 'mysql', 'host' => 'remote.invalid', 'database' => 'picksports_test_readiness', 'username' => 'picksports_test']), false, 'picksports_test_readiness'],
    'production schema even with opt-in' => [syntheticTestDatabaseConfiguration(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'production', 'username' => 'picksports_test']), false, 'production'],
    'privileged MySQL user' => [syntheticTestDatabaseConfiguration(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'picksports_test_readiness', 'username' => 'root']), false, 'picksports_test_readiness'],
]);

it('accepts an explicitly selected loopback disposable MySQL database', function () {
    TestDatabaseSafety::assertSafe(syntheticTestDatabaseConfiguration([
        'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'picksports_test_readiness', 'username' => 'picksports_test',
    ]), false, 'picksports_test_readiness');

    expect(true)->toBeTrue();
});

it('requires a matching private temporary socket opt-in', function () {
    $directory = sys_get_temp_dir().'/picksports-test-safety-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $socket = $directory.'/mysql.sock';
    $configuration = syntheticTestDatabaseConfiguration([
        'driver' => 'mysql', 'unix_socket' => $socket, 'database' => 'picksports_test_readiness', 'username' => 'picksports_test',
    ]);
    try {
        TestDatabaseSafety::assertSafe($configuration, false, 'picksports_test_readiness', $socket);
        expect(fn () => TestDatabaseSafety::assertSafe($configuration, false, 'picksports_test_readiness'))
            ->toThrow(RuntimeException::class);
        chmod($directory, 0755);
        clearstatcache(true, $directory);
        expect(fn () => TestDatabaseSafety::assertSafe($configuration, false, 'picksports_test_readiness', $socket))
            ->toThrow(RuntimeException::class);
    } finally {
        rmdir($directory);
    }
});
