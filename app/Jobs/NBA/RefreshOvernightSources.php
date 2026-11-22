<?php

namespace App\Jobs\NBA;

use App\Services\CommandHeartbeatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/** Dedicated source-only worker; never competes for the existing NFL sync queue. */
class RefreshOvernightSources implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 720;

    public function __construct()
    {
        $this->onConnection('redis')->onQueue('nba-sources');
    }

    public function uniqueId(): string
    {
        return 'nba:overnight-source-worker';
    }

    public function handle(CommandHeartbeatService $health): void
    {
        // A queued job cannot revive a subsequently disabled bridge.
        if (! config('nba.source_bridge.enabled', false)) {
            return;
        }
        $started = hrtime(true);
        $queuedAt = $this->job?->payload()['createdAt'] ?? null;
        $wait = is_numeric($queuedAt) ? max(0, (int) ((microtime(true) - $queuedAt) * 1000)) : null;
        $code = Artisan::call('nba:refresh-overnight-sources');
        if ($code !== 0) {
            throw new RuntimeException('NBA source-only bridge failed; inspect acquisition completion health.');
        }
        // Command completion is distinct from verified collection/assessment status in nba_source_bridge.
        $health->recordSuccess('nba:refresh-overnight-sources', 'nba', 'nba_source_worker', [
            'queue' => $this->queue, 'queue_wait_ms' => $wait, 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
            'acquisition_result_source' => 'nba_source_bridge',
        ]);
    }

    public function failed(?Throwable $error): void
    {
        app(CommandHeartbeatService::class)->recordFailure('nba:refresh-overnight-sources', 'nba', 'nba_source_worker',
            'Source worker failed; inspect completion records and scope.', ['queue' => $this->queue, 'error_class' => $error ? class_basename($error) : null]);
    }
}
