<?php

use App\Support\Database\MySqlCliConnection;
use Pdo\Mysql;

function syntheticNativeMysqlConfiguration(array $overrides = []): array
{
    return array_replace([
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'synthetic_dump', 'username' => 'synthetic_user',
        'password' => 'synthetic-secret', 'charset' => 'utf8mb4', 'options' => [],
    ], $overrides);
}

it('resolves URL credentials and transport using the framework precedence without exposing the password', function () {
    $connection = MySqlCliConnection::fromConfiguration(syntheticNativeMysqlConfiguration([
        'url' => 'mysql://url_user:synthetic%40password@db.example.invalid:3311/url_database?charset=latin1',
        'options' => [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true],
    ]));

    expect($connection->database())->toBe('url_database')
        ->and($connection->arguments())->toBe([
            '--no-defaults', '--no-login-paths', '--protocol=TCP', '--host=db.example.invalid', '--port=3311',
            '--ssl-mode=VERIFY_IDENTITY', '--ssl-ca=/synthetic/ca.pem', '--user=url_user', '--default-character-set=latin1',
        ])
        ->and($connection->environment()['MYSQL_PWD'])->toBe('synthetic@password')
        ->and(implode(' ', $connection->arguments()))->not->toContain('synthetic@password');
    ob_start();
    var_dump($connection);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('synthetic@password');
});

it('selects the configured socket without inventing a TCP fallback', function () {
    $connection = MySqlCliConnection::fromConfiguration(syntheticNativeMysqlConfiguration([
        'unix_socket' => '/synthetic/mysql.sock', 'host' => 'remote.example.invalid',
    ]));

    expect($connection->arguments())->toBe([
        '--no-defaults', '--no-login-paths', '--protocol=SOCKET', '--socket=/synthetic/mysql.sock',
        '--user=synthetic_user', '--default-character-set=utf8mb4',
    ]);
});

it('forwards all supported TLS settings with identity verification', function () {
    $connection = MySqlCliConnection::fromConfiguration(syntheticNativeMysqlConfiguration(['options' => [
        Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_CAPATH => '/synthetic/cas',
        Mysql::ATTR_SSL_CERT => '/synthetic/client.pem', Mysql::ATTR_SSL_KEY => '/synthetic/client.key',
        Mysql::ATTR_SSL_CIPHER => 'synthetic-cipher',
    ]]));

    expect($connection->arguments())->toContain('--ssl-mode=VERIFY_IDENTITY', '--ssl-ca=/synthetic/ca.pem',
        '--ssl-capath=/synthetic/cas', '--ssl-cert=/synthetic/client.pem', '--ssl-key=/synthetic/client.key',
        '--ssl-cipher=synthetic-cipher');
});

it('fails closed instead of silently discarding connection settings', function (array $overrides) {
    expect(fn () => MySqlCliConnection::fromConfiguration(syntheticNativeMysqlConfiguration($overrides)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'disabled certificate verification' => [['options' => [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]]],
    'unsupported PDO option' => [['options' => [PDO::ATTR_TIMEOUT => 30]]],
    'TLS over a socket' => [['unix_socket' => '/synthetic/mysql.sock', 'options' => [Mysql::ATTR_SSL_CA => '/ca.pem']]],
    'multiple hosts' => [['host' => ['one.invalid', 'two.invalid']]],
    'read replica' => [['read' => ['host' => 'replica.invalid']]],
    'unsupported URL SSL option' => [['url' => 'mysql://user:secret@db.invalid/database?sslmode=disabled']],
    'ambiguous localhost socket' => [['host' => 'localhost']],
    'invalid port' => [['port' => 70000]],
    'option injection in charset' => [['charset' => "utf8mb4\n--ssl-mode=DISABLED"]],
]);

it('does not inherit unrelated application secrets into native client processes', function () {
    putenv('PICKSPORTS_SYNTHETIC_APPLICATION_SECRET=do-not-copy');
    try {
        $environment = MySqlCliConnection::fromConfiguration(syntheticNativeMysqlConfiguration())->environment();

        expect($environment)->not->toHaveKey('PICKSPORTS_SYNTHETIC_APPLICATION_SECRET')
            ->and($environment['MYSQL_PWD'])->toBe('synthetic-secret');
    } finally {
        putenv('PICKSPORTS_SYNTHETIC_APPLICATION_SECRET');
    }
});
