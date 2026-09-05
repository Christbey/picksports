#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dumpPath = $argv[1] ?? null;
$targetDatabase = $argv[2] ?? null;

if (! is_string($dumpPath) || ! is_file($dumpPath) || ! is_readable($dumpPath)) {
    fwrite(STDERR, "The first argument must be a readable MySQL dump file.\n");
    exit(2);
}

if (! is_string($targetDatabase) || preg_match('/\A[a-zA-Z0-9_]+\z/', $targetDatabase) !== 1) {
    fwrite(STDERR, "The second argument must be a MySQL database name containing only letters, numbers, and underscores.\n");
    exit(2);
}

/** @return non-empty-string */
$findExecutable = static function (string $name): string {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException("Required executable not found: {$name}");
};

$connection = config('database.connections.'.config('database.default'));

if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
    fwrite(STDERR, "The configured default database connection must use MySQL.\n");
    exit(2);
}

$existingDatabase = DB::selectOne(
    'select schema_name from information_schema.schemata where schema_name = ?',
    [$targetDatabase],
);

if ($existingDatabase !== null) {
    fwrite(STDERR, "Refusing to import because the target database already exists: {$targetDatabase}\n");
    exit(2);
}

DB::statement("CREATE DATABASE `{$targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $mysql = $findExecutable('mysql');
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$environment = getenv();
$environment['MYSQL_PWD'] = (string) ($connection['password'] ?? '');

$importProcess = proc_open(
    [
        $mysql,
        '--protocol=TCP',
        '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
        '--port='.(string) ($connection['port'] ?? 3306),
        '--user='.(string) ($connection['username'] ?? ''),
        '--default-character-set=utf8mb4',
        '--database='.$targetDatabase,
    ],
    [
        0 => ['file', $dumpPath, 'rb'],
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    null,
    $environment,
);

if (! is_resource($importProcess)) {
    fwrite(STDERR, "Unable to start the MySQL import process. The empty target database was left in place.\n");
    exit(1);
}

$exitCode = proc_close($importProcess);

if ($exitCode !== 0) {
    fwrite(STDERR, "Import failed with exit code {$exitCode}. The partial target database was left in place.\n");
    exit(1);
}

fwrite(STDOUT, "Imported {$dumpPath} into {$targetDatabase}.\n");
