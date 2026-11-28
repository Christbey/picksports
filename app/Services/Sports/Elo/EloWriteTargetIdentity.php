<?php

namespace App\Services\Sports\Elo;

use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;
use WeakMap;

/** Only nonsecret authoritative write-target facts are hashed; aliases and credentials are excluded. */
class EloWriteTargetIdentity
{
    private static ?WeakMap $memoryIdentities = null;

    private static ?string $processNonce = null;

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function describe(Connection $connection): array
    {
        try {
            $environment = config('app.env');
            if (! is_string($environment) || trim($environment) === ''
                || str_ends_with((string) $connection->getNameWithReadWriteType(), '::read')) {
                throw new RuntimeException('Missing environment or an explicit read-role connection.');
            }
            $driver = $connection->getDriverName();
            if ($driver === 'mysql') {
                $row = $connection->selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name', [], false);
                if (! is_string($row?->server_uuid) || preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/i', $row->server_uuid) !== 1
                    || ! is_string($row->database_name) || $row->database_name === '') {
                    throw new RuntimeException('Missing authoritative MySQL identity.');
                }
                $identity = ['driver' => $driver, 'server_uuid' => strtolower($row->server_uuid),
                    'database' => $row->database_name, 'environment' => $environment];
                $scope = 'physical_database';
            } elseif ($driver === 'sqlite') {
                $rows = $connection->select('PRAGMA database_list', [], false);
                $main = collect($rows)->first(fn ($row): bool => $row->name === 'main');
                if ($main === null || ! is_string($main->file)) {
                    throw new RuntimeException('Missing authoritative SQLite identity.');
                }
                if ($main->file === '') {
                    self::$memoryIdentities ??= new WeakMap;
                    self::$processNonce ??= bin2hex(random_bytes(16));
                    $pdo = $connection->getPdo();
                    self::$memoryIdentities[$pdo] ??= bin2hex(random_bytes(16));
                    $identity = ['driver' => $driver, 'environment' => $environment, 'process' => getmypid(),
                        'process_nonce' => self::$processNonce, 'pdo_nonce' => self::$memoryIdentities[$pdo]];
                    $scope = 'process_local_memory';
                } else {
                    $path = realpath($main->file);
                    if ($path === false || ! is_file($path)) {
                        throw new RuntimeException('Unavailable canonical SQLite file.');
                    }
                    $identity = ['driver' => $driver, 'environment' => $environment, 'canonical_database_path' => $path];
                    $scope = 'physical_database';
                }
            } else {
                throw new RuntimeException('Unsupported authoritative database identity.');
            }

            return ['fingerprint' => $this->hasher->hash($identity), 'scope' => $scope, 'portable_approval' => $scope !== 'process_local_memory'];
        } catch (Throwable) {
            throw new RuntimeException('Authoritative Elo write-target identity is unavailable.');
        }
    }
}
