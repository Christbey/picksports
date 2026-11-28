<?php

namespace App\Services\Sports\Elo;

use App\Exceptions\EloAdmissionRefused;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use PDO;
use Throwable;

final class EloSqlClockPolicy
{
    public static function policy(): array
    {
        if (config('elo_integrity.sql_clock.mysql_session_timezone', '+00:00') !== '+00:00'
            || config('elo_integrity.sql_clock.reconnect', 'fresh_admission_required') !== 'fresh_admission_required') {
            throw new EloAdmissionRefused('sql_clock_policy_invalid', [], []);
        }

        return ['version' => 'managed-source-sql-clock-v1', 'application_timezone' => config('app.timezone'), 'mysql_session_timezone' => '+00:00',
            'reconnect' => 'fresh_admission_required', 'handles' => 'transient_only', 'legacy_off' => 'unchanged'];
    }

    public static function matchesPortablePolicy(mixed $stored): bool
    {
        $expected = self::policy();
        if (! is_array($stored) || count($stored) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $stored) || get_debug_type($stored[$key]) !== get_debug_type($value)) {
                return false;
            }
        }

        $hasher = app(CanonicalPayloadHasher::class);

        return $hasher->hash($stored) === $hasher->hash($expected);
    }

    public function capture(Connection $connection): EloSqlClockToken
    {
        return $this->captureRole($connection, true);
    }

    /** Only for reviewed selectors that explicitly use the primary PDO for every source query. */
    public function capturePrimary(Connection $connection): EloSqlClockToken
    {
        return $this->captureRole($connection, false);
    }

    private function captureRole(Connection $connection, bool $checkRead): EloSqlClockToken
    {
        $targetDigest = $this->inspectCurrent($connection, $checkRead);

        return new EloSqlClockToken($connection, $connection->getPdo(), $checkRead ? $connection->getReadPdo() : $connection->getPdo(), self::policy(), $targetDigest, $checkRead);
    }

    public function assertUnchanged(EloSqlClockToken $token, Connection $connection): void
    {
        if ($token->portablePolicy() !== self::policy()) {
            throw new EloAdmissionRefused('sql_clock_policy_changed_requires_fresh_admission', [], []);
        }
        $token->assertHandles($connection);
        $token->assertTarget($this->inspectCurrent($connection, $token->checksRead()));
        $token->assertHandles($connection);
    }

    public function assertCurrent(Connection $connection, bool $checkRead = false): void
    {
        $this->inspectCurrent($connection, $checkRead);
    }

    private function inspectCurrent(Connection $connection, bool $checkRead): string
    {
        self::policy();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new EloAdmissionRefused('sql_clock_driver_unsupported', [], []);
        }
        try {
            $write = $connection->getPdo();
            $read = $checkRead ? $connection->getReadPdo() : $write;
            if ($connection->transactionLevel() === 0 && $write->inTransaction()) {
                throw new EloAdmissionRefused('sql_clock_untracked_inherited_transaction', [], []);
            }
            if ($read !== $write && $read->inTransaction()) {
                throw new EloAdmissionRefused('sql_clock_untracked_read_transaction', [], []);
            }
            $writeTarget = ['process_local_sqlite'];
            if ($driver === 'mysql') {
                $writeTarget = $this->requireUtc($write);
                if ($read !== $write && $this->requireUtc($read) !== $writeTarget) {
                    throw new EloAdmissionRefused('sql_clock_read_write_target_mismatch', [], []);
                }
            } elseif ($read !== $write) {
                $writeFile = $this->sqliteFile($write);
                $writeTarget = [$writeFile];
                $readFile = $this->sqliteFile($read);
                if ($writeFile === null || $readFile === null || $writeFile !== $readFile) {
                    throw new EloAdmissionRefused('sql_clock_read_write_target_mismatch', [], []);
                }
            } elseif ($driver === 'sqlite') {
                $writeTarget = [$this->sqliteFile($write) ?? 'process_local_sqlite'];
            }

            return hash('sha256', json_encode([$driver, $writeTarget, $connection->getTablePrefix()], JSON_THROW_ON_ERROR));
        } catch (EloAdmissionRefused $refusal) {
            throw $refusal;
        } catch (Throwable) {
            throw new EloAdmissionRefused('sql_clock_session_metadata_unavailable', [], []);
        }
    }

    private function requireUtc(PDO $pdo): array
    {
        // Direct PDO avoids accepting Connection's automatic lost-connection retry as the same admission.
        $statement = $pdo->query('SELECT @@SESSION.time_zone AS session_timezone, @@server_uuid AS server_uuid, DATABASE() AS database_name');
        $metadata = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (! is_array($metadata) || ($metadata['session_timezone'] ?? null) !== '+00:00') {
            throw new EloAdmissionRefused('sql_clock_session_not_explicit_utc', [], []);
        }
        if (! is_string($metadata['server_uuid'] ?? null) || $metadata['server_uuid'] === ''
            || ! is_string($metadata['database_name'] ?? null) || $metadata['database_name'] === '') {
            throw new EloAdmissionRefused('sql_clock_session_metadata_unavailable', [], []);
        }

        return [$metadata['server_uuid'], $metadata['database_name']];
    }

    private function sqliteFile(PDO $pdo): ?string
    {
        $statement = $pdo->query('PRAGMA database_list');
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if (($database['name'] ?? null) === 'main' && is_string($database['file'] ?? null) && $database['file'] !== '') {
                $file = realpath($database['file']);

                return $file === false ? null : $file;
            }
        }

        return null;
    }
}
