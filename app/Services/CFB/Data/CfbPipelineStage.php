<?php

namespace App\Services\CFB\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CfbPipelineStage
{
    public function run(string $scope, string $stage, array $inputs, callable $work, bool $resume = true, int $failureCooldownSeconds = 0): array
    {
        $hash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
        $key = ['scope' => $scope, 'stage' => $stage, 'input_hash' => $hash, 'version' => '1'];
        $lock = Cache::lock('cfb:stage:'.hash('sha256', $scope.':'.$stage), 600);
        if (! $lock->get()) {
            return ['state' => 'busy'];
        }
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $prefix = $connection->getTablePrefix();
            $table = $connection->table('cfb_pipeline_steps')->useWritePdo();
            // A nested transaction remains caller-owned: it does not make a
            // reservation durable before work when an outer transaction exists.
            $reservation = $connection->transaction(function () use ($table, $key, $resume, $failureCooldownSeconds): array {
                $row = (clone $table)->where($key)->lockForUpdate()->first();
                if ($resume && $row?->state === 'complete') {
                    return ['result' => json_decode($row->result, true)];
                }
                if ($failureCooldownSeconds > 0 && in_array($row?->state, ['failed', 'blocked', 'partial'], true)
                    && $row->completed_at && Carbon::parse($row->completed_at)->addSeconds($failureCooldownSeconds)->isFuture()) {
                    return ['result' => json_decode($row->result, true)];
                }
                $rawGeneration = $row?->attempts ?? 0;
                if (! is_int($rawGeneration) && (! is_string($rawGeneration) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $rawGeneration))) {
                    throw new \RuntimeException('Invalid CFB stage receipt generation.');
                }
                $generation = (int) $rawGeneration;
                if ($generation < 0 || $generation >= 4294967295 || (string) $generation !== (string) $rawGeneration) {
                    return ['result' => ['state' => 'busy', 'reason' => 'receipt_generation_exhausted']];
                }
                $next = $generation + 1;
                $values = ['state' => 'running', 'attempts' => $next, 'started_at' => now(), 'completed_at' => null, 'updated_at' => now()];
                if ($row === null) {
                    $id = (clone $table)->insertGetId([...$key, ...$values, 'created_at' => now()]);
                } else {
                    $id = $row->id;
                    $changed = (clone $table)->where($key)->where('id', $id)->where('attempts', $generation)->update($values);
                    if ($changed !== 1) {
                        throw new \RuntimeException('CFB stage receipt reservation changed.');
                    }
                }

                return ['id' => $id, 'generation' => $next];
            });
            if (array_key_exists('result', $reservation)) {
                return $reservation['result'];
            }
            try {
                $result = $work();
            } catch (\Throwable $e) {
                report($e);
                $result = ['state' => 'failed', 'error' => $e->getMessage()];
            }
            if ($connection->getPdo() !== $pdo || $connection->getTablePrefix() !== $prefix) {
                return ['state' => 'busy', 'reason' => 'receipt_connection_changed'];
            }
            // The work itself is not rolled back here. Only this generation may
            // publish its terminal receipt. A superseded generation cannot author
            // completion or overwrite the receipt belonging to a newer worker.
            $changed = (clone $table)->where($key)->where('id', $reservation['id'])
                ->where('attempts', $reservation['generation'])->where('state', 'running')
                ->update(['state' => $result['state'], 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now(), 'updated_at' => now()]);
            if ($changed !== 1) {
                return ['state' => 'busy', 'reason' => 'receipt_generation_superseded'];
            }

            return $result;
        } finally {
            $lock->release();
        }
    }
}
