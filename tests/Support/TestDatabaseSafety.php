<?php

namespace Tests\Support;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class TestDatabaseSafety
{
    /** @param array<string, mixed> $configuration */
    public static function assertSafe(
        array $configuration,
        bool $cached,
        ?string $disposableDatabase = null,
        ?string $disposableSocket = null,
    ): void {
        if ($cached || ($configuration['app']['env'] ?? null) !== 'testing') {
            throw new RuntimeException('Test database safety requires uncached testing configuration.');
        }

        $database = $configuration['database'] ?? [];
        $connection = $database['connections'][$database['default'] ?? ''] ?? null;
        try {
            $connection = is_array($connection)
                ? (new ConfigurationUrlParser)->parseConfiguration($connection)
                : null;
        } catch (Throwable) {
            throw new RuntimeException('Test database safety rejected an invalid connection URL.');
        }
        if (! is_array($connection)) {
            throw new RuntimeException('Test database safety requires an explicit connection.');
        }
        if (array_key_exists('read', $connection) || array_key_exists('write', $connection)) {
            throw new RuntimeException('Test database safety rejects read/write connection overrides.');
        }

        if (($connection['driver'] ?? null) === 'sqlite' && ($connection['database'] ?? null) === ':memory:') {
            return;
        }

        if (($connection['driver'] ?? null) !== 'mysql'
            || ! is_string($disposableDatabase)
            || preg_match('/\Apicksports_test_[a-zA-Z0-9_]+\z/', $disposableDatabase) !== 1
            || ($connection['database'] ?? null) !== $disposableDatabase
            || ($connection['username'] ?? null) !== 'picksports_test') {
            throw new RuntimeException('Test database safety permits only in-memory SQLite or an explicitly selected disposable MySQL database.');
        }

        $socket = $connection['unix_socket'] ?? '';
        if ($socket !== '') {
            if (! is_string($socket) || $socket !== $disposableSocket || ! self::privateTemporarySocket($socket)) {
                throw new RuntimeException('Test database safety requires an explicitly selected private temporary MySQL socket.');
            }

            return;
        }

        if (! in_array($connection['host'] ?? null, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Test database safety rejects non-loopback MySQL targets.');
        }
    }

    private static function privateTemporarySocket(string $socket): bool
    {
        if (! str_starts_with($socket, '/') || is_link($socket)) {
            return false;
        }
        $directory = realpath(dirname($socket));
        $roots = array_filter(array_map(realpath(...), [sys_get_temp_dir(), '/tmp', '/private/tmp']));
        $withinTemporaryRoot = $directory !== false && array_any($roots,
            fn (string $root): bool => str_starts_with($directory, rtrim($root, '/').'/'));
        $permissions = $directory !== false ? fileperms($directory) : false;

        return $withinTemporaryRoot && $permissions !== false && ($permissions & 0077) === 0
            && function_exists('posix_geteuid') && fileowner($directory) === posix_geteuid();
    }
}
