<?php

use App\Services\Database\SchemaHealthInspector;

function schemaHealthFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'database' => 'synthetic', 'driver' => 'mysql', 'server_version' => '8.4.7-7',
        'tables' => [['name' => 'migrations']],
        'migration_state' => ['pending' => [], 'unknown' => []],
    ], $overrides);
}

it('refuses healthy migration admission when applied history is missing from disk', function () {
    $result = (new SchemaHealthInspector)->inspect(schemaHealthFixture([
        'migration_state' => ['unknown' => ['2026_01_01_000000_missing_history']],
    ]));
    expect($result['healthy'])->toBeFalse();
});

it('does not mistake a MariaDB mysql-driver connection for the required MySQL engine', function (string $version) {
    $result = (new SchemaHealthInspector)->inspect(schemaHealthFixture(['server_version' => $version]), minimumMysqlVersion: '8.4');
    expect($result['healthy'])->toBeFalse();
})->with(['10.11.8-MariaDB', '11.4.5-mariadb-log']);

it('does not certify unknown server versions or invalid minimum version requirements', function (string $server, string $minimum) {
    $result = (new SchemaHealthInspector)->inspect(schemaHealthFixture(['server_version' => $server]), minimumMysqlVersion: $minimum);
    expect($result['healthy'])->toBeFalse();
})->with([['unknown', '0.0'], ['8.4.7', ''], ['8.4.7', '8.4garbage'], ['unknown-99', '8.4'], ['proxy8.4garbage', '8.4'], ['8.4garbage', '8.4']]);

it('retains valid MySQL minimum-version admission', function () {
    $result = (new SchemaHealthInspector)->inspect(schemaHealthFixture(), minimumMysqlVersion: '8.4');
    expect($result['healthy'])->toBeTrue();
});
