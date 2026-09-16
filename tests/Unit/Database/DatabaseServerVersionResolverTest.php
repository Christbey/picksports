<?php

use App\Services\Database\DatabaseServerVersionResolver;
use Illuminate\Database\Connection;

it('uses the server reported mysql version instead of a proxy handshake version', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('select version() as server_version')
        ->andReturn((object) ['server_version' => '8.4.7']);
    $connection->shouldNotReceive('getServerVersion');

    $version = (new DatabaseServerVersionResolver)->resolve($connection);

    expect($version)->toBe('8.4.7');
});

it('falls back through mysql version sources when the preferred query is unavailable', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('select version() as server_version')
        ->andThrow(new RuntimeException('restricted'));
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('select @@version as server_version')
        ->andReturn(['server_version' => '8.4.7-commercial']);
    $connection->shouldNotReceive('getServerVersion');

    $version = (new DatabaseServerVersionResolver)->resolve($connection);

    expect($version)->toBe('8.4.7-commercial');
});

it('uses the handshake version as a safe final fallback', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')->twice()->andThrow(new RuntimeException('restricted'));
    $connection->shouldReceive('getServerVersion')->once()->andReturn('8.0.39');

    $version = (new DatabaseServerVersionResolver)->resolve($connection);

    expect($version)->toBe('8.0.39');
});

it('resolves a mariadb engine version through the live server query', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mariadb');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('select version() as server_version')
        ->andReturn((object) ['server_version' => '11.4.2-MariaDB']);

    expect((new DatabaseServerVersionResolver)->resolve($connection))
        ->toBe('11.4.2-MariaDB');
});
