<?php

namespace App\Jobs;

use App\Services\CommandHeartbeatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
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

    public function __construct(public string $commandLine, public string $scheduledName, public ?string $heartbeatUrl = null)
    {
        $liveDispatcher = str_starts_with($commandLine, 'espn:')
            && ! str_contains($commandLine, '--sync')
            && (str_contains($scheduledName, 'Live Scoreboard') || str_contains($scheduledName, 'Sync Game Details'));
        $this->onConnection('redis');
        $this->onQueue($liveDispatcher || $commandLine === 'cfb:sync-live-betting' ? 'default' : 'sync');
        $this->timeout = $this->queue === 'default' ? 110 : 1700;
    }

    public function uniqueId(): string
    {
        return 'scheduled-command:'.hash('sha256', $this->scheduledName.'|'.$this->commandLine);
    }

    public function handle(CommandHeartbeatService $heartbeats): void
    {
        $started = hrtime(true);
        $sport = $heartbeats->inferSportFromCommand($this->commandLine);
        $metadata = ['scheduled_name' => $this->scheduledName, 'queue' => $this->queue];
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
