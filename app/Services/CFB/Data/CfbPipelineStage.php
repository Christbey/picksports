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
            $table = DB::table('cfb_pipeline_steps');
            $row = (clone $table)->where($key)->first();
            if ($resume && $row?->state === 'complete') {
                return json_decode($row->result, true);
            }
            if ($failureCooldownSeconds > 0 && in_array($row?->state, ['failed', 'blocked', 'partial'], true)
                && $row->completed_at && Carbon::parse($row->completed_at)->addSeconds($failureCooldownSeconds)->isFuture()) {
                return json_decode($row->result, true);
            }
            $table->updateOrInsert($key, ['state' => 'running', 'attempts' => ($row?->attempts ?? 0) + 1, 'started_at' => now(), 'completed_at' => null, 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            try {
                $result = $work();
            } catch (\Throwable $e) {
                report($e);
                $result = ['state' => 'failed', 'error' => $e->getMessage()];
            }
            (clone $table)->where($key)->update(['state' => $result['state'], 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now(), 'updated_at' => now()]);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
