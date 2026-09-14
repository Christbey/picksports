<?php

namespace App\Console\Commands\AI;

use App\Models\AiGeneration;
use Illuminate\Console\Command;

class ReconcileStaleGenerationsCommand extends Command
{
    protected $signature = 'ai:reconcile-stale-generations
        {--minutes=15 : Mark running generations older than this threshold as failed}
        {--limit=500 : Maximum rows to reconcile per pass}
        {--dry-run : Report stale rows without updating them}';

    protected $description = 'Close AI generation rows orphaned by terminated or timed-out processes';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $completedAt = now();

        $generations = AiGeneration::query()
            ->where('status', 'running')
            ->where('started_at', '<=', $completedAt->copy()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($generations->isEmpty()) {
            $this->info('No stale running AI generations found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("Found {$generations->count()} stale running AI generation(s); no rows updated.");

            return self::SUCCESS;
        }

        $reconciled = 0;
        foreach ($generations as $generation) {
            $latencyMs = $generation->started_at
                ? min(4_294_967_295, max(0, $generation->started_at->diffInMilliseconds($completedAt)))
                : null;

            $reconciled += AiGeneration::query()
                ->whereKey($generation->getKey())
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'error_code' => 'orphaned_process_timeout',
                    'latency_ms' => $latencyMs,
                    'metadata' => array_replace_recursive($generation->metadata ?? [], [
                        'reconciled_by' => 'ai:reconcile-stale-generations',
                        'stale_after_minutes' => $minutes,
                    ]),
                    'completed_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);
        }

        $this->info("Reconciled {$reconciled} stale AI generation(s).");

        return self::SUCCESS;
    }
}
