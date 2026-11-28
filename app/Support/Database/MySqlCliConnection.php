<?php

namespace App\Support\Database;

use Illuminate\Database\ConfigurationUrlParser;
use InvalidArgumentException;
use Pdo\Mysql;
use Throwable;

/** Transport settings shared by the native MySQL import and export tools. */
final class MySqlCliConnection
{
    /** @param list<string> $arguments */
    private function __construct(
        private readonly string $database,
        private readonly array $arguments,
        private readonly string $password,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        try {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (Throwable) {
            throw new InvalidArgumentException('The database connection URL is invalid.');
        }

        if (($configuration['driver'] ?? null) !== 'mysql') {
            throw new InvalidArgumentException('Native database tools require a MySQL connection.');
        }

        $knownKeys = ['driver', 'database', 'host', 'port', 'username', 'password', 'unix_socket', 'charset',
            'collation', 'prefix', 'prefix_indexes', 'strict', 'engine', 'options', 'name', 'timezone',
            'modes', 'version', 'isolation_level', 'use_db_after_connecting'];
        if (array_diff(array_keys($configuration), $knownKeys) !== []) {
            throw new InvalidArgumentException('The database connection contains unsupported native-tool settings.');
        }

        $username = self::stringValue($configuration['username'] ?? null);
        $database = self::stringValue($configuration['database'] ?? null);
        $password = $configuration['password'] ?? '';
        if (! is_scalar($password) || str_contains((string) $password, "\0")) {
            throw new InvalidArgumentException('The database password has an unsupported format.');
        }
        $password = (string) $password;
        $charset = self::stringValue($configuration['charset'] ?? 'utf8mb4');
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $charset) !== 1) {
            throw new InvalidArgumentException('The database character set is invalid.');
        }

        // Both flags precede all other options, so ambient client configuration
        // cannot redirect the target or silently override certificate validation.
        $arguments = ['--no-defaults', '--no-login-paths'];
        $socket = $configuration['unix_socket'] ?? '';
        if (! is_string($socket)) {
            throw new InvalidArgumentException('The database socket must be a single path.');
        }
        if ($socket !== '') {
            $arguments[] = '--protocol=SOCKET';
            $arguments[] = '--socket='.self::stringValue($socket);
        } else {
            $host = self::stringValue($configuration['host'] ?? '127.0.0.1');
            if ($host === 'localhost') {
                throw new InvalidArgumentException('Native tools require an explicit socket for localhost, or an explicit TCP host.');
            }
            $port = filter_var($configuration['port'] ?? 3306, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('The database port is invalid.');
            }
            $arguments[] = '--protocol=TCP';
            $arguments[] = '--host='.$host;
            $arguments[] = '--port='.$port;
            // Fail closed if the client/server cannot provide authenticated TLS.
            $arguments[] = '--ssl-mode=VERIFY_IDENTITY';
        }

        $options = $configuration['options'] ?? [];
        if (! is_array($options)) {
            throw new InvalidArgumentException('The database PDO options are invalid.');
        }
        $tlsOptions = [
            Mysql::ATTR_SSL_CA => '--ssl-ca=',
            Mysql::ATTR_SSL_CAPATH => '--ssl-capath=',
            Mysql::ATTR_SSL_CERT => '--ssl-cert=',
            Mysql::ATTR_SSL_KEY => '--ssl-key=',
            Mysql::ATTR_SSL_CIPHER => '--ssl-cipher=',
        ];
        foreach ($options as $option => $value) {
            if ($socket !== '') {
                throw new InvalidArgumentException('Native socket connections cannot silently discard configured PDO options.');
            }
            if ($option === Mysql::ATTR_SSL_VERIFY_SERVER_CERT && $value === true) {
                continue;
            }
            if (! isset($tlsOptions[$option])) {
                throw new InvalidArgumentException('The database PDO options cannot be safely translated to native tooling.');
            }
            $arguments[] = $tlsOptions[$option].self::stringValue($value);
        }

        $arguments[] = '--user='.$username;
        $arguments[] = '--default-character-set='.$charset;

        return new self($database, $arguments, $password);
    }

    public function database(): string
    {
        return $this->database;
    }

    /** @return array{database:string, password:string} */
    public function __debugInfo(): array
    {
        return ['database' => $this->database, 'password' => '[redacted]'];
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        $environment = [];
        foreach (['PATH', 'LANG', 'LC_ALL', 'TZ', 'SYSTEMROOT'] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }
        // Passwords never appear in argv, command strings, or helper diagnostics.
        $environment['MYSQL_PWD'] = $this->password;

        return $environment;
    }

    private static function stringValue(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || (string) $value === '' || preg_match('/[\x00\r\n]/', (string) $value) === 1) {
            throw new InvalidArgumentException('A required database connection setting is missing or invalid.');
        }

        return (string) $value;
    }
}
