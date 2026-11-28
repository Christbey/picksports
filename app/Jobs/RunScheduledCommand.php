<?php

namespace App\Jobs;

use App\Services\CommandHeartbeatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

class RunScheduledCommand implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 86400;

    public int $timeout;

    // Defaults also apply to jobs serialized before worker execution groups existed.
    public ?int $maxExceptions = null;

    public ?int $executionRetryDeadline = null;

    public function __construct(public string $commandLine, public string $scheduledName, public ?string $heartbeatUrl = null)
    {
        $liveDispatcher = str_starts_with($commandLine, 'espn:')
            && ! str_contains($commandLine, '--sync')
            && (str_contains($scheduledName, 'Live Scoreboard') || str_contains($scheduledName, 'Sync Game Details'));
        $this->onConnection('redis');
        $this->onQueue($liveDispatcher || $commandLine === 'cfb:sync-live-betting' ? 'default' : 'sync');
        $this->timeout = $this->queue === 'default' ? 110 : 1700;
        if ($this->executionGroup() !== null) {
            $this->executionRetryDeadline = now()->getTimestamp() + $this->uniqueFor;
            $this->maxExceptions = 1;
        }
    }

    public function displayName(): string
    {
        return $this->scheduledName;
    }

    public function uniqueId(): string
    {
        return 'scheduled-command:'.hash('sha256', $this->scheduledName.'|'.$this->commandLine);
    }

    public function retryUntil(): ?int
    {
        return $this->executionRetryDeadline;
    }

    public function executionGroup(): ?string
    {
        $verb = strtok($this->commandLine, ' ');

        return match ($verb) {
            'nfl:run-pregame-pipeline', 'nfl:report-canonical-cutover-readiness' => 'nfl-canonical-generation-readiness',
            'mlb:generate-predictions' => 'mlb-generation:'.hash('sha256', $this->commandLine),
            default => null,
        };
    }

    public function executionLockKey(): ?string
    {
        $group = $this->executionGroup();

        return $group === null ? null : 'scheduled-command-execution:'.$group;
    }

    public function handle(CommandHeartbeatService $heartbeats): void
    {
        $lock = null;
        if ($key = $this->executionLockKey()) {
            $lease = $this->timeout + 60;
            $reservation = (int) config('queue.connections.redis.retry_after', 2100);
            if ($lease <= 0 || $lease >= $reservation) {
                throw new RuntimeException('Scheduled execution lock must outlive the job timeout and expire before its queue reservation.');
            }
            $lock = Cache::lock($key, $lease);
            if (! $lock->get()) {
                if ($this->job === null) {
                    throw new RuntimeException('Scheduled execution group is busy and requires a queued retry.');
                }
                $this->release(30);

                return;
            }
        }

        try {
            $this->executeCommand($heartbeats);
        } finally {
            $lock?->release();
        }
    }

    private function executeCommand(CommandHeartbeatService $heartbeats): void
    {
        $started = hrtime(true);
        $sport = $heartbeats->inferSportFromCommand($this->commandLine);
        $metadata = ['scheduled_name' => $this->scheduledName, 'queue' => $this->queue];
        $queuedAt = $this->job?->payload()['createdAt'] ?? null;
        if (is_numeric($queuedAt)) {
            $metadata['queue_wait_ms'] = max(0, (int) ((microtime(true) - $queuedAt) * 1000));
        }
        try {
            $code = Artisan::call(str_replace('\\', '\\\\', $this->commandLine), [], new ConsoleOutput);
            $metadata += ['exit_code' => $code, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'peak_memory_bytes' => memory_get_peak_usage(true)];
            if ($code !== 0) {
                throw new RuntimeException('Scheduled command exited with code '.$code);
            }
            if (str_contains($this->commandLine, '--queued')) {
                return;
            }
            $heartbeats->recordSuccess($this->commandLine, $sport, 'worker', $metadata);
            if ($this->heartbeatUrl) {
                try {
                    Http::connectTimeout(3)->timeout(5)->get($this->heartbeatUrl)->throw();
                } catch (Throwable $error) {
                    report($error);
                }
            }
        } finally {
            if ($this->queue === 'sync') {
                app('queue.worker')->shouldQuit = true;
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        app(CommandHeartbeatService::class)->recordFailure($this->commandLine,
            app(CommandHeartbeatService::class)->inferSportFromCommand($this->commandLine), 'worker',
            $error?->getMessage() ?? 'Scheduled worker terminated', ['scheduled_name' => $this->scheduledName, 'queue' => $this->queue]);
    }
}
