<?php

namespace App\Support\Database;

use Illuminate\Database\ConfigurationUrlParser;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/** Offline helpers: no Laravel bootstrap, provider calls or database connection. */
final class NbaRolloutPreflightSupport
{
    /** @return array{string,string,string,array<int,mixed>} */
    public static function parameters(array $connection): array
    {
        try {
            $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable) {
            throw new RuntimeException('database_url_invalid');
        }
        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('mysql_configuration_required');
        }
        $knownKeys = ['driver', 'host', 'port', 'database', 'username', 'password', 'unix_socket', 'charset',
            'collation', 'prefix', 'prefix_indexes', 'strict', 'engine', 'options', 'name'];
        if (array_diff(array_keys($connection), $knownKeys) !== []) {
            throw new RuntimeException('unsupported_database_configuration');
        }
        $database = self::dsnValue($connection['database'] ?? null);
        $charset = self::dsnValue($connection['charset'] ?? 'utf8mb4');
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $charset) !== 1) {
            throw new RuntimeException('unsupported_database_charset');
        }
        $username = self::credential($connection['username'] ?? null);
        $password = self::credential($connection['password'] ?? '');
        $socket = $connection['unix_socket'] ?? '';
        if (! is_string($socket)) {
            throw new RuntimeException('unsupported_database_socket');
        }
        if ($socket !== '') {
            $dsn = 'mysql:unix_socket='.self::dsnValue($socket).';dbname='.$database;
        } else {
            $host = self::dsnValue($connection['host'] ?? '127.0.0.1');
            $port = filter_var($connection['port'] ?? 3306, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                throw new RuntimeException('unsupported_database_port');
            }
            $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$database;
        }
        $options = $connection['options'] ?? [];
        if (! is_array($options)) {
            throw new RuntimeException('unsupported_database_options');
        }
        $tls = [Mysql::ATTR_SSL_CA, Mysql::ATTR_SSL_CAPATH, Mysql::ATTR_SSL_CERT, Mysql::ATTR_SSL_KEY, Mysql::ATTR_SSL_CIPHER];
        foreach ($options as $key => $value) {
            if (in_array($key, $tls, true)) {
                if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
                    throw new RuntimeException('unsupported_database_tls_option');
                }
            } elseif ($key === Mysql::ATTR_SSL_VERIFY_SERVER_CERT && $value === true) {
                continue;
            } elseif (! in_array($key, [PDO::ATTR_ERRMODE, PDO::ATTR_TIMEOUT], true)) {
                // Initializer SQL, persistent sessions, routing and unknown PDO behavior are not a read-only preflight contract.
                throw new RuntimeException('unsupported_database_option');
            }
        }
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_TIMEOUT] = 10;

        return [$dsn.';charset='.$charset, $username, $password, $options];
    }

    public static function sourceMetadata(array $source): array
    {
        return [
            'id' => (int) ($source['id'] ?? 0),
            'team_id' => isset($source['team_id']) ? (int) $source['team_id'] : null,
            'source_key' => self::label($source['source_key'] ?? null),
            'kind' => self::label($source['kind'] ?? null),
            'enabled' => in_array($source['enabled'] ?? null, [true, 1, '1'], true),
            'checked_at_stored' => self::storedTimestamp($source['checked_at'] ?? null),
            'succeeded_at_stored' => self::storedTimestamp($source['succeeded_at'] ?? null),
            'url_sha256' => is_string($source['url'] ?? null) ? hash('sha256', $source['url']) : null,
            'has_error' => ($source['last_error'] ?? null) !== null && ($source['last_error'] ?? '') !== '',
        ];
    }

    public static function applicationOrigin(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 4096) {
            return null;
        }
        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || preg_match('/\A(?:[a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])\z/', $parts['host']) !== 1) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function dsnValue(mixed $value): string
    {
        if (! is_string($value) || $value === '' || preg_match('/[;\x00-\x1f\x7f]/', $value)) {
            throw new RuntimeException('unsupported_database_dsn_value');
        }

        return $value;
    }

    private static function credential(mixed $value): string
    {
        if (! is_string($value) || str_contains($value, "\0")) {
            throw new RuntimeException('unsupported_database_credential');
        }

        return $value;
    }

    private static function label(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 64 && preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $value) === 1 ? $value : null;
    }

    private static function storedTimestamp(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $value) === 1 ? $value : null;
    }
}
