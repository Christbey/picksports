<?php

namespace App\Services\Infrastructure;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

class QueueJobTiming
{
    public function subscribe($events): void
    {
        $attempts = new WeakMap;

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($attempts): void {
            if ($event->connectionName === 'sync') {
                return;
            }

            $queuedAt = $event->job->payload()['createdAt'] ?? null;
            $attempts[$event->job] = [
                'started' => hrtime(true),
                'age_ms' => is_numeric($queuedAt) ? max(0, (int) ((microtime(true) - $queuedAt) * 1000)) : null,
            ];
        });

        $finish = function (Job $job, string $connection, string $outcome) use ($attempts): void {
            $attempt = $attempts[$job] ?? null;
            unset($attempts[$job]);
            if ($attempt === null) {
                return;
            }

            try {
                Log::info('queue.job.timing', [
                    'job' => $job->resolveName(),
                    'uuid' => $job->uuid(),
                    'connection' => $connection,
                    'queue' => $job->getQueue(),
                    'attempt' => $job->attempts(),
                    'outcome' => $outcome,
                    'enqueue_to_start_ms' => $attempt['age_ms'],
                    'runtime_ms' => (int) ((hrtime(true) - $attempt['started']) / 1_000_000),
                    'process_peak_bytes' => memory_get_peak_usage(true),
                ]);
            } catch (Throwable) {
                // A logging outage must not change the job's outcome or trigger a retry.
            }
        };

        $events->listen(JobProcessed::class, fn (JobProcessed $event) => $finish($event->job, $event->connectionName,
            $event->job->hasFailed() ? 'failed' : ($event->job->isReleased() ? 'released' : 'completed')));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $finish($event->job, $event->connectionName,
            $event->job->hasFailed() ? 'failed' : 'exception'));
        $events->listen(JobTimedOut::class, fn (JobTimedOut $event) => $finish($event->job, $event->connectionName, 'timeout'));
    }
}
