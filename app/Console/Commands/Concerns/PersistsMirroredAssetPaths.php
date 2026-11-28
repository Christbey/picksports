<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait PersistsMirroredAssetPaths
{
    /** @param array<string,string> $changes */
    protected function persistMirroredAssetPaths(Model $model, array $changes): bool
    {
        if ($changes === []) {
            return false;
        }
        $literal = new ($model::class);
        $literal->setConnection($model->getConnectionName());
        $connection = $model->getConnection();
        $pdo = $connection->getPdo();
        if (! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Mirrored asset persistence requires a supported database driver.');
        }
        $key = $literal->getKeyName();
        $id = $model->getRawOriginal($key);
        if (! $model->exists || $model->getTable() !== $literal->getTable()
            || $model->getKeyName() !== $key || (string) $model->getKey() !== (string) $id
            || $literal->getConnection() !== $connection) {
            throw new RuntimeException('Mirrored asset update has no current model owner.');
        }
        $original = [];
        foreach ($changes as $field => $value) {
            if (! in_array($field, ['logo', 'logo_url', 'headshot_url'], true)
                || ! is_string($value) || $value === '' || strlen($value) > 4096) {
                throw new RuntimeException('Mirrored asset path is unsupported or oversized.');
            }
            $original[$field] = $model->getRawOriginal($field);
        }
        $owner = [];
        foreach (['team_id', 'espn_id'] as $field) {
            if (array_key_exists($field, $model->getAttributes())) {
                $owner[$field] = $model->getRawOriginal($field);
            }
        }
        $currentConnection = fn (): bool => $model->getConnection() === $connection
            && $literal->getConnection() === $connection && $connection->getPdo() === $pdo;

        return $connection->transaction(function () use ($model, $literal, $connection, $key, $id, $original, $changes, $owner, $currentConnection): bool {
            if (! $currentConnection()) {
                throw new RuntimeException('Mirrored asset connection changed before persistence.');
            }
            $header = function () use ($connection, $literal, $key, $id, $changes, $owner): ?object {
                $query = $connection->table($literal->getTable())->useWritePdo()
                    ->where($key, $id)->lockForUpdate()->select([$key, ...array_keys($owner)]);
                foreach (array_keys($changes) as $field) {
                    $column = $connection->getQueryGrammar()->wrap($field);
                    $length = $connection->getDriverName() === 'mysql'
                        ? "OCTET_LENGTH({$column})" : "LENGTH(CAST({$column} AS BLOB))";
                    $query->selectRaw("CASE WHEN {$length} <= 4096 THEN {$column} ELSE NULL END AS {$column}");
                }

                return $query->first();
            };
            $matches = function (?object $row, array $values): bool {
                if ($row === null) {
                    return false;
                }
                foreach ($values as $field => $value) {
                    $actual = $row->{$field};
                    if (($actual === null || $value === null) ? $actual !== $value : (string) $actual !== (string) $value) {
                        return false;
                    }
                }

                return true;
            };
            $before = $header();
            if (! $currentConnection()) {
                throw new RuntimeException('Mirrored asset connection changed during source admission.');
            }
            if (! $matches($before, $owner)) {
                throw new RuntimeException('Mirrored asset row or team owner changed during source IO.');
            }
            if ($matches($before, $changes)) {
                return false;
            }
            if (! $matches($before, $original)) {
                throw new RuntimeException('Mirrored asset source changed during source IO.');
            }
            $write = clone $model;
            $write->forceFill($changes);
            if (! $write->save() || ! $write->exists || ! $currentConnection()
                || $write->getConnection() !== $connection || $write->getTable() !== $literal->getTable()
                || (string) $write->getKey() !== (string) $id
                || ! $matches($header(), [...$owner, ...$changes])) {
                throw new RuntimeException('Requested mirrored asset paths were not persisted.');
            }

            return true;
        });
    }
}
