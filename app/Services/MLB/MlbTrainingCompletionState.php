<?php

namespace App\Services\MLB;

use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Models\ModelTrainingWorker;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Narrow current completion headers: never select run parameters or unbounded JSON bodies. */
final class MlbTrainingCompletionState
{
    /** Also bound the supplied snapshot before decoding its frozen metadata. */
    public static function metadata(ModelRun $run): array
    {
        $raw = $run->getAttributes()['metadata'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (! is_string($raw)) {
            throw new RuntimeException('MLB completion snapshot metadata must be plain stored JSON.');
        }
        MlbTabularJsonBudget::inspect($raw, 2097152, 50000, 24);
        $value = json_decode($raw, true, 24, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('MLB completion snapshot metadata must be an object.');
        }

        return $value;
    }

    public static function cycle(Connection $connection, string $id, bool $lock = true): ModelRun
    {
        $model = (new ModelRun)->setConnection($connection->getName());
        $query = $model->newModelQuery()->useWritePdo()->whereKey($id)
            ->selectRaw(self::scalarSql($connection, ['id' => 36, 'sport' => 16, 'run_type' => 64, 'status' => 32, 'model_version' => 256, 'feature_version' => 256, 'blend_version' => 256, 'config_hash' => 64, 'code_version' => 64]));
        $run = self::read($connection, $query, 'metadata', $lock);
        if (! $run instanceof ModelRun || $run->sport !== 'mlb' || $run->run_type !== 'weekly_training_cycle') {
            throw new RuntimeException('MLB completion requires its current training cycle.');
        }

        return $run;
    }

    public static function latestAttempt(Connection $connection, string $cycleId): ?ModelTrainingAttempt
    {
        $model = (new ModelTrainingAttempt)->setConnection($connection->getName());
        $query = $model->newModelQuery()->useWritePdo()->where('cycle_run_id', $cycleId)
            ->orderByDesc('attempt_number')->selectRaw(self::scalarSql($connection, ['id' => 20, 'cycle_run_id' => 36, 'attempt_number' => 20, 'owner_token' => 36, 'python_run_id' => 36, 'status' => 32, 'started_at' => 32, 'lease_expires_at' => 32, 'output_hash' => 64]));
        $attempt = self::read($connection, $query, 'result', true, false);

        return $attempt instanceof ModelTrainingAttempt ? $attempt : null;
    }

    public static function worker(Connection $connection, string $id): ModelTrainingWorker
    {
        $model = (new ModelTrainingWorker)->setConnection($connection->getName());
        $query = $model->newModelQuery()->useWritePdo()->whereKey($id)->selectRaw(self::scalarSql($connection,
            ['id' => 36, 'token_hash' => 64, 'expires_at' => 32, 'revoked_at' => 32]));
        foreach (['sports', 'abilities'] as $field) {
            $length = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH('.$field.')' : 'length(CAST('.$field.' AS BLOB))';
            $query->selectRaw('COALESCE('.$length.', 0) AS _'.$field.'_bytes')
                ->selectRaw('CASE WHEN '.$length.' <= 8192 THEN '.$field.' ELSE NULL END AS '.$field);
        }
        $worker = $query->lockForUpdate()->first();
        if ($worker === null || $worker->getConnection() !== $connection || (int) $worker->getRawOriginal('_completion_scalar_invalid') !== 0) {
            throw new RuntimeException('MLB completion current credential is missing or exceeds its scalar budget.');
        }
        foreach (['sports', 'abilities'] as $field) {
            $raw = $worker->getRawOriginal($field);
            if (! is_string($raw) || strlen($raw) !== (int) $worker->getRawOriginal('_'.$field.'_bytes')) {
                throw new RuntimeException('MLB completion credential scope exceeds its byte budget.');
            }
            MlbTabularJsonBudget::inspect($raw, 8192, 1000, 8);
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($value) || ! array_is_list($value) || count($value) > 16) {
                throw new RuntimeException('MLB completion credential scope exceeds its entry budget.');
            }
            foreach ($value as $entry) {
                if (! is_string($entry) || strlen($entry) > 64) {
                    throw new RuntimeException('MLB completion credential scope contains an invalid entry.');
                }
            }
            $worker->offsetUnset('_'.$field.'_bytes');
        }
        $worker->offsetUnset('_completion_scalar_invalid');

        return $worker;
    }

    /** Binds the exact stored upload receipts, not worker-supplied paths or a late expanded join. */
    public static function uploads(Connection $connection, int $attemptId, bool $lock = true): array
    {
        $query = $connection->table('model_training_uploads')->useWritePdo()->where('attempt_id', $attemptId)
            ->selectRaw(self::scalarSql($connection, ['id' => 20, 'attempt_id' => 20, 'path' => 191, 'disk' => 255,
                'object_key' => 4096, 'sha256' => 64, 'bytes' => 20]))->orderBy('path')->limit(65);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        if ($rows->count() > 64) {
            throw new RuntimeException('MLB completion upload receipt count exceeds its bound.');
        }
        $result = [];
        $total = 0;
        foreach ($rows as $row) {
            if ((int) $row->_completion_scalar_invalid !== 0 || preg_match('/^[a-f0-9]{64}$/D', $row->sha256) !== 1) {
                throw new RuntimeException('MLB completion upload receipt exceeds its scalar budget.');
            }
            foreach (['id', 'attempt_id', 'bytes'] as $field) {
                $value = filter_var($row->$field, FILTER_VALIDATE_INT);
                if ($value === false || $value < ($field === 'bytes' ? 0 : 1)) {
                    throw new RuntimeException('MLB completion upload receipt integer is invalid.');
                }
                $row->$field = $value;
            }
            $total += $row->bytes;
            if ($row->bytes > 8388608 || $total > 83886080) {
                throw new RuntimeException('MLB completion upload receipt bytes exceed the fixed numeric bundle bound.');
            }
            $attributes = (array) $row;
            unset($attributes['_completion_scalar_invalid']);
            $result[] = $attributes;
        }

        return $result;
    }

    private static function scalarSql(Connection $connection, array $fields): string
    {
        $select = $invalid = [];
        foreach ($fields as $field => $maximum) {
            $length = $connection->getDriverName() === 'mysql'
                ? 'OCTET_LENGTH('.$field.')' : 'length(CAST('.$field.' AS BLOB))';
            $select[] = 'CASE WHEN '.$length.' <= '.$maximum.' THEN '.$field.' ELSE NULL END AS '.$field;
            $invalid[] = 'CASE WHEN '.(in_array($field, ['output_hash', 'revoked_at', 'code_version'], true) ? '' : $field.' IS NULL OR ').$length.' > '.$maximum.' THEN 1 ELSE 0 END';
        }

        return implode(', ', $select).', ('.implode(' + ', $invalid).') AS _completion_scalar_invalid';
    }

    private static function read(Connection $connection, mixed $query, string $jsonField, bool $lock, bool $required = true): ?Model
    {
        $length = $connection->getDriverName() === 'mysql'
            ? 'OCTET_LENGTH('.$jsonField.')' : 'length(CAST('.$jsonField.' AS BLOB))';
        $query->selectRaw('COALESCE('.$length.', 0) AS _completion_json_bytes')
            ->selectRaw('CASE WHEN '.$length.' <= 2097152 THEN '.$jsonField.' ELSE NULL END AS '.$jsonField);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            if ($required) {
                throw new RuntimeException('MLB completion current owner disappeared.');
            }

            return null;
        }
        if ((int) $row->getRawOriginal('_completion_scalar_invalid') !== 0) {
            throw new RuntimeException('MLB completion current scalar exceeds its byte budget.');
        }
        if ($row->getConnection() !== $connection) {
            throw new RuntimeException('MLB completion resolved a different connection.');
        }
        $raw = $row->getRawOriginal($jsonField);
        $size = (int) $row->getRawOriginal('_completion_json_bytes');
        if ($size < 0 || $size > 2097152 || ($size !== 0 && ! is_string($raw))) {
            throw new RuntimeException('MLB completion current JSON exceeds its byte budget.');
        }
        if ($raw !== null) {
            MlbTabularJsonBudget::inspect($raw, 2097152, 50000, 24);
            $value = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new RuntimeException('MLB completion current JSON must be an object or result array.');
            }
        }
        $row->offsetUnset('_completion_json_bytes');
        $row->offsetUnset('_completion_scalar_invalid');

        return $row;
    }
}
