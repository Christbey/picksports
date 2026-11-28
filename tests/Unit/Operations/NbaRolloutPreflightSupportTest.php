<?php

use App\Support\Database\NbaRolloutPreflightSupport;
use Illuminate\Database\ConfigurationUrlParser;
use Pdo\Mysql;

function rolloutSyntheticConnection(array $overrides = []): array
{
    return array_replace(['driver' => 'mysql', 'url' => null, 'host' => 'synthetic.invalid', 'port' => 3306,
        'database' => 'synthetic_base', 'username' => 'synthetic_reader', 'password' => 'synthetic-only',
        'unix_socket' => '', 'charset' => 'utf8mb4', 'options' => []], $overrides);
}

it('uses the same query override target as Laravel without opening a connection', function () {
    $configuration = rolloutSyntheticConnection(['url' => 'mysql://synthetic_reader:synthetic-only@synthetic.invalid/base?host=override.invalid&database=override_schema&charset=latin1']);
    $resolved = (new ConfigurationUrlParser)->parseConfiguration($configuration);
    [$dsn] = NbaRolloutPreflightSupport::parameters($configuration);
    expect($dsn)->toBe('mysql:host='.$resolved['host'].';port='.$resolved['port'].';dbname='.$resolved['database'].';charset='.$resolved['charset']);
});

it('uses a resolved socket override instead of the URL TCP host', function () {
    [$dsn] = NbaRolloutPreflightSupport::parameters(rolloutSyntheticConnection(['url' => 'mysql://synthetic_reader:synthetic-only@synthetic.invalid/base?unix_socket=%2Fsynthetic%2Fmysql.sock']));
    expect($dsn)->toBe('mysql:unix_socket=/synthetic/mysql.sock;dbname=base;charset=utf8mb4');
});

it('refuses unsafe or unsupported transport configuration before PDO', function (array $overrides) {
    expect(fn () => NbaRolloutPreflightSupport::parameters(rolloutSyntheticConnection($overrides)))->toThrow(RuntimeException::class);
})->with([
    'URL driver override' => [['url' => 'pgsql://synthetic:synthetic@synthetic.invalid/base']],
    'read write routing' => [['read' => ['host' => 'other.invalid']]],
    'DSN database injection' => [['database' => 'base;host=other.invalid']],
    'DSN socket injection' => [['unix_socket' => '/synthetic/sock;dbname=other']],
    'array host' => [['host' => ['synthetic.invalid']]],
    'out of range port' => [['port' => 70000]],
    'SQL initializer' => [['options' => [Mysql::ATTR_INIT_COMMAND => 'SET @synthetic=1']]],
]);

it('preserves configured TLS while bounding timeout and forcing exception mode', function () {
    [, , , $options] = NbaRolloutPreflightSupport::parameters(rolloutSyntheticConnection(['options' => [Mysql::ATTR_SSL_CA => '/synthetic/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true, PDO::ATTR_TIMEOUT => 90, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]));
    expect($options[Mysql::ATTR_SSL_CA])->toBe('/synthetic/ca.pem')
        ->and($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT])->toBeTrue()
        ->and($options[PDO::ATTR_TIMEOUT])->toBe(10)
        ->and($options[PDO::ATTR_ERRMODE])->toBe(PDO::ERRMODE_EXCEPTION);
});

it('emits bounded allowlisted source diagnostics without raw URL errors or extra data', function () {
    $source = ['id' => 12, 'team_id' => 4, 'source_key' => str_repeat('x', 10000), 'kind' => 'official',
        'url' => 'https://synthetic.invalid/?token=synthetic-secret', 'allowed_hosts' => ['synthetic.invalid'],
        'last_error' => 'synthetic-secret '.str_repeat('e', 10000), 'enabled' => 1,
        'checked_at' => '2026-11-26 01:02:03', 'succeeded_at' => null, 'unexpected' => 'synthetic-secret'];
    $report = NbaRolloutPreflightSupport::sourceMetadata($source);
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('synthetic-secret')
        ->and($report['id'])->toBe(12)->and($report['has_error'])->toBeTrue()
        ->and($report['url_sha256'])->toBe(hash('sha256', $source['url']))
        ->and($json)->not->toContain('synthetic-secret')
        ->and(strlen($json))->toBeLessThan(1024);
});

it('preserves plain synthetic TCP transport and credentials', function () {
    [$dsn, $user, $password] = NbaRolloutPreflightSupport::parameters(rolloutSyntheticConnection());
    expect($dsn)->toBe('mysql:host=synthetic.invalid;port=3306;dbname=synthetic_base;charset=utf8mb4')
        ->and($user)->toBe('synthetic_reader')->and($password)->toBe('synthetic-only');
});

it('preserves an explicit synthetic socket', function () {
    [$dsn] = NbaRolloutPreflightSupport::parameters(rolloutSyntheticConnection(['unix_socket' => '/synthetic/mysql.sock']));
    expect($dsn)->toBe('mysql:unix_socket=/synthetic/mysql.sock;dbname=synthetic_base;charset=utf8mb4');
});

it('prints only the application origin without cached URL credentials or query material', function (string $url, string $expected) {
    expect(NbaRolloutPreflightSupport::applicationOrigin($url))->toBe($expected);
})->with([
    'private URL components' => ['https://synthetic-user:synthetic-secret@picksports.app/secret-path?token=synthetic-secret#secret-fragment', 'https://picksports.app'],
    'explicit port' => ['https://picksports.app:8443/private/path?synthetic=1', 'https://picksports.app:8443'],
    'plain origin control' => ['https://picksports.app', 'https://picksports.app'],
]);
