<?php

namespace App\Jobs\CFB;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunPregamePipeline implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public ?string $runId = null;

    public function __construct(public array $arguments)
    {
        $this->runId = (string) Str::uuid();
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'cfb-pregame-'.($this->arguments['--season'] ?? 'current');
    }

    public function handle(): void
    {
        $this->runId ??= $this->job?->uuid() ?? (string) Str::uuid();
        $started = now()->toIso8601String();
        $this->storeStatus(['state' => 'running', 'arguments' => $this->arguments,
            'started_at' => $started], true);
        $code = Artisan::call('cfb:run-pregame-pipeline', $this->arguments);
        $this->storeStatus(['state' => $code === 0 ? 'complete' : ($code === 2 ? 'partial' : 'failed'),
            'exit_code' => $code, 'arguments' => $this->arguments, 'started_at' => $started,
            'completed_at' => now()->toIso8601String(), 'output' => mb_strcut(Artisan::output(), -32768, null, 'UTF-8')]);
        if ($code !== 0 && $code !== 2) {
            throw new RuntimeException("CFB pregame pipeline exited with code {$code}.");
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->runId ??= $this->job?->uuid();
        $this->storeStatus(['state' => 'failed', 'arguments' => $this->arguments,
            'completed_at' => now()->toIso8601String(), 'error' => $error?->getMessage()]);
    }

    private function storeStatus(array $values, bool $starting = false): void
    {
        Cache::lock('cfb:pregame:worker-status-lock', 10)->block(5, function () use ($values, $starting): void {
            $status = Cache::get('cfb:pregame:worker-status', []);
            if (! $starting && (! $this->runId || ($status['run_id'] ?? null) !== $this->runId)) {
                return;
            }
            Cache::put('cfb:pregame:worker-status', [...($starting ? [] : $status), ...$values,
                'run_id' => $this->runId], now()->addDay());
        });
    }
}
