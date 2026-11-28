#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\Database\MySqlCliConnection;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outputPath = $argv[1] ?? null;
$fastImport = in_array('--fast-import', array_slice($argv, 2), true);

if (! is_string($outputPath) || $outputPath === '' || ! str_ends_with($outputPath, '.sql.gz')) {
    fwrite(STDERR, "Usage: php scripts/export-laravel-cloud-dump.php /path/to/backup.sql.gz [--fast-import]\n");
    exit(2);
}

if (file_exists($outputPath)) {
    fwrite(STDERR, "Refusing to overwrite existing file: {$outputPath}\n");
    exit(2);
}

$outputDirectory = dirname($outputPath);

// Dumps contain private application data. Create both directories and output
// files privately even when the caller's shell has a permissive umask.
umask(0077);

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0700, true) && ! is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDirectory}\n");
    exit(2);
}

$directoryPermissions = fileperms($outputDirectory);
if ($directoryPermissions === false || ($directoryPermissions & 0077) !== 0) {
    fwrite(STDERR, "Refusing to export into a directory accessible by other users. Choose a private directory: {$outputDirectory}\n");
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

try {
    $connection = MySqlCliConnection::fromConfiguration((array) config('database.connections.'.config('database.default')));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

try {
    $mysqldump = $findExecutable('mysqldump');
    $gzip = $findExecutable('gzip');
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$dumpCommand = [
    $mysqldump,
    ...$connection->arguments(),
    '--single-transaction',
    '--quick',
    '--skip-lock-tables',
    '--skip-add-locks',
    '--skip-disable-keys',
    $fastImport ? '--extended-insert' : '--skip-extended-insert',
    $fastImport ? '--net-buffer-length=131072' : '--net-buffer-length=16384',
    '--set-gtid-purged=OFF',
    '--no-tablespaces',
    '--hex-blob',
    '--',
    $connection->database(),
];

$environment = $connection->environment();

$gzipProcess = proc_open(
    [$gzip, '-6'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $outputPath, 'xb'],
        2 => STDERR,
    ],
    $gzipPipes,
);

if (! is_resource($gzipProcess)) {
    fwrite(STDERR, "Unable to start gzip.\n");
    exit(1);
}

$dumpProcess = proc_open(
    $dumpCommand,
    [
        0 => ['file', '/dev/null', 'r'],
        1 => $gzipPipes[0],
        2 => STDERR,
    ],
    $dumpPipes,
    null,
    $environment,
);

fclose($gzipPipes[0]);

if (! is_resource($dumpProcess)) {
    proc_terminate($gzipProcess);
    proc_close($gzipProcess);
    unlink($outputPath);
    fwrite(STDERR, "Unable to start mysqldump.\n");
    exit(1);
}

$dumpExitCode = proc_close($dumpProcess);
$gzipExitCode = proc_close($gzipProcess);

if ($dumpExitCode !== 0 || $gzipExitCode !== 0) {
    unlink($outputPath);
    fwrite(STDERR, "Export failed (mysqldump={$dumpExitCode}, gzip={$gzipExitCode}).\n");
    exit(1);
}

fwrite(STDOUT, "Created Laravel Cloud import dump: {$outputPath}\n");
