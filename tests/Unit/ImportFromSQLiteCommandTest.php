<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\TestCase;

uses(TestCase::class);

it('retires legacy import commands before resolving a target, reading files or starting processes', function (string $environment, string $command, array $arguments) {
    config(['app.env' => $environment, 'database.default' => 'mysql',
        'database.connections.mysql.host' => 'unreachable.example.invalid',
        'database.connections.mysql.database' => 'production',
        'database.connections.mysql.password' => "synthetic'password"]);
    DB::shouldReceive('connection')->never();
    DB::shouldReceive('purge')->never();
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('statement')->never();
    File::shouldReceive('exists')->never();
    File::shouldReceive('get')->never();
    Process::fake();

    $this->artisan($command, $arguments)
        ->expectsOutputToContain($command.' is retired.')
        ->assertExitCode(Command::FAILURE);

    Process::assertNothingRan();
})->with(['local', 'testing', 'production'])->with([
    'all SQLite tables' => ['db:import-from-sqlite', []],
    'selected SQLite tables' => ['db:import-from-sqlite', ['--tables' => ['users', 'example']]],
    'SQL dump' => ['db:import-sql', ['file' => '/synthetic/must-not-read.sql']],
]);

it('keeps retired console entry points from invoking a native import client', function (string $command) {
    $directory = sys_get_temp_dir().'/picksports-retired-import-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $marker = $directory.'/must-not-run';
    $input = $directory.'/synthetic.sql';
    file_put_contents($input, '-- synthetic SQL only');
    file_put_contents($directory.'/mysql', "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\n");
    chmod($directory.'/mysql', 0700);
    $arguments = [PHP_BINARY, base_path('artisan'), $command, '--no-interaction'];
    if ($command === 'db:import-sql') {
        $arguments[] = $input;
    }
    try {
        $process = new NativeProcess($arguments, base_path(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-cache.php',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
            'DB_DATABASE' => 'synthetic', 'DB_USERNAME' => 'synthetic', 'DB_PASSWORD' => 'synthetic_secret',
            'DB_URL' => '', 'DB_SOCKET' => '', 'PATH' => $directory.':/usr/bin:/bin',
        ]);
        $process->setTimeout(15);
        $process->run();

        expect($process->getExitCode())->toBe(Command::FAILURE)
            ->and($process->getOutput().$process->getErrorOutput())->toContain($command.' is retired.')
            ->and($process->getOutput().$process->getErrorOutput())->not->toContain('synthetic_secret', 'SQLSTATE')
            ->and(file_exists($marker))->toBeFalse()
            ->and(file_get_contents($input))->toBe('-- synthetic SQL only');
    } finally {
        unlink($input);
        unlink($directory.'/mysql');
        if (file_exists($marker)) {
            unlink($marker);
        }
        rmdir($directory);
    }
})->with(['db:import-from-sqlite', 'db:import-sql']);
