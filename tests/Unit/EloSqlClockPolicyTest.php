<?php

use App\Exceptions\EloAdmissionRefused;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

uses(TestCase::class);

function isolatedEloClockConnection(?PDO $pdo = null): SQLiteConnection
{
    return new SQLiteConnection($pdo ?? new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
}

it('keeps clock tokens transient and debug output free of connection handles', function () {
    $connection = isolatedEloClockConnection();
    $token = (new EloSqlClockPolicy)->capture($connection);
    expect(fn () => serialize($token))->toThrow(LogicException::class)
        ->and(fn () => json_encode($token, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
    ob_start();
    var_dump($token);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('PDO', 'WeakReference', 'targetDigest', 'password');
});

it('refuses replacement connection handles even when their declared target matches', function () {
    $connection = isolatedEloClockConnection();
    $policy = new EloSqlClockPolicy;
    $token = $policy->capture($connection);
    $connection->setPdo(new PDO('sqlite::memory:'));
    expect(fn () => $policy->assertUnchanged($token, $connection))->toThrow(EloAdmissionRefused::class);
});

it('refuses independent in-memory read and write databases', function () {
    $connection = isolatedEloClockConnection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    expect(fn () => (new EloSqlClockPolicy)->capture($connection))->toThrow(EloAdmissionRefused::class);
});

it('admits separate PDO handles for the same canonical SQLite file', function () {
    $path = tempnam(sys_get_temp_dir(), 'elo-clock-');
    try {
        $connection = isolatedEloClockConnection(new PDO('sqlite:'.$path));
        $connection->setReadPdo(new PDO('sqlite:'.$path));
        $policy = new EloSqlClockPolicy;
        $token = $policy->capture($connection);
        $policy->assertUnchanged($token, $connection);
        expect($token->portablePolicy()['handles'])->toBe('transient_only');
    } finally {
        unset($connection);
        unlink($path);
    }
});

it('refuses table prefix drift after capture', function () {
    $connection = isolatedEloClockConnection();
    $policy = new EloSqlClockPolicy;
    $token = $policy->capture($connection);
    $connection->setTablePrefix('changed_');
    expect(fn () => $policy->assertUnchanged($token, $connection))->toThrow(EloAdmissionRefused::class);
});

it('refuses untracked inherited PDO transactions without rolling them back', function () {
    $connection = isolatedEloClockConnection();
    $pdo = $connection->getPdo();
    $pdo->beginTransaction();
    try {
        expect(fn () => (new EloSqlClockPolicy)->capture($connection))->toThrow(EloAdmissionRefused::class)
            ->and($pdo->inTransaction())->toBeTrue();
    } finally {
        $pdo->rollBack();
    }
});

it('supports the same handle inside a tracked Laravel transaction', function () {
    $connection = isolatedEloClockConnection();
    $policy = new EloSqlClockPolicy;
    $token = $policy->capture($connection);
    $connection->transaction(function () use ($connection, $policy, $token) {
        $policy->assertUnchanged($token, $connection);
        expect($connection->transactionLevel())->toBe(1);
    });
});

it('refuses application clock policy drift without changing the database session', function () {
    $connection = isolatedEloClockConnection();
    $policy = new EloSqlClockPolicy;
    $token = $policy->capture($connection);
    config(['app.timezone' => config('app.timezone') === 'UTC' ? 'America/Chicago' : 'UTC']);
    expect(fn () => $policy->assertUnchanged($token, $connection))->toThrow(EloAdmissionRefused::class);
});

it('refuses changed portable policy values types and unsupported list fields', function ($replacement) {
    $policy = EloSqlClockPolicy::policy();
    $policy['mysql_session_timezone'] = $replacement;
    expect(EloSqlClockPolicy::matchesPortablePolicy($policy))->toBeFalse();
})->with(['different value' => ['-09:00'], 'boolean' => [true], 'integer' => [0], 'list' => [['+00:00', '-09:00']]]);

it('requires the exact portable policy key set', function () {
    $policy = EloSqlClockPolicy::policy();
    $policy['unexpected'] = 'extra';
    expect(EloSqlClockPolicy::matchesPortablePolicy($policy))->toBeFalse();
    unset($policy['unexpected'], $policy['reconnect']);
    expect(EloSqlClockPolicy::matchesPortablePolicy($policy))->toBeFalse();
});

it('anchors explicit primary-only capture without touching an unused read resolver', function () {
    $connection = isolatedEloClockConnection();
    $readCalls = 0;
    $connection->setReadPdo(function () use (&$readCalls) {
        $readCalls++;
        throw new LogicException('Unused replica must not be resolved.');
    });
    $policy = new EloSqlClockPolicy;
    $token = $policy->capturePrimary($connection);
    $policy->assertUnchanged($token, $connection);
    expect($readCalls)->toBe(0);
    $connection->setPdo(new PDO('sqlite::memory:'));
    expect(fn () => $policy->assertUnchanged($token, $connection))->toThrow(EloAdmissionRefused::class)
        ->and($readCalls)->toBe(0);
});

it('does not grant read-source admission from a primary-only token', function () {
    $connection = isolatedEloClockConnection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $policy = new EloSqlClockPolicy;
    $token = $policy->capturePrimary($connection);
    $policy->assertUnchanged($token, $connection);
    expect($token->checksRead())->toBeFalse()
        ->and(fn () => $policy->capture($connection))->toThrow(EloAdmissionRefused::class);
});

it('retains the read-write role after capture and refuses a newly divergent read handle', function () {
    $connection = isolatedEloClockConnection();
    $policy = new EloSqlClockPolicy;
    $token = $policy->capture($connection);
    expect($token->checksRead())->toBeTrue();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    expect(fn () => $policy->assertUnchanged($token, $connection))->toThrow(EloAdmissionRefused::class);
});
