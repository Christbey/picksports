<?php

namespace App\Jobs\CFB;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunPregamePipeline implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public array $arguments)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'cfb-pregame-'.($this->arguments['--season'] ?? 'current');
    }

    public function handle(): void
    {
        $started = now()->toIso8601String();
        Cache::put('cfb:pregame:worker-status', ['state' => 'running', 'arguments' => $this->arguments,
            'started_at' => $started], now()->addDay());
        $code = Artisan::call('cfb:run-pregame-pipeline', $this->arguments);
        Cache::put('cfb:pregame:worker-status', ['state' => $code === 0 ? 'complete' : ($code === 2 ? 'partial' : 'failed'),
            'exit_code' => $code, 'arguments' => $this->arguments, 'started_at' => $started,
            'completed_at' => now()->toIso8601String(), 'output' => mb_strcut(Artisan::output(), -32768, null, 'UTF-8')], now()->addDay());
    }

    public function failed(?Throwable $error): void
    {
        Cache::put('cfb:pregame:worker-status', ['state' => 'failed', 'arguments' => $this->arguments,
            'completed_at' => now()->toIso8601String(), 'error' => $error?->getMessage()], now()->addDay());
    }
}
