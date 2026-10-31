<?php

namespace App\Jobs\CFB;

use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RunPregamePipeline implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1700;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public string $runId;

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
        $this->runId ??= (string) Str::uuid();
        $started = now()->toIso8601String();
        Cache::put('cfb:pregame:worker-status', ['run_id' => $this->runId, 'state' => 'running', 'arguments' => $this->arguments,
            'started_at' => $started], now()->addDay());
        try {
            $code = Artisan::call('cfb:run-pregame-pipeline', [...$this->arguments, '--run-id' => $this->runId]);
        } catch (Throwable $error) {
            $this->recordOutcome('failed', 1, $started, null, $error->getMessage());
            $this->fail($error);

            return;
        }
        $state = $code === 0 ? 'complete' : ($code === 2 ? 'partial' : 'failed');
        $this->recordOutcome($state, $code, $started, Artisan::output());
        if ($state !== 'complete') {
            $this->fail(new \RuntimeException('CFB pregame worker failed: '.$this->runId));
        }
        if ($state === 'complete' && ($name = $this->arguments['--heartbeat-name'] ?? null)) {
            $slug = (string) Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
            if ($url = config('services.forge.heartbeats.'.$slug)) {
                try {
                    Http::connectTimeout(3)->timeout(5)->get($url)->throw();
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->runId ??= (string) Str::uuid();
        if (! CommandHeartbeat::where('source', 'worker')->where('metadata->run_id', $this->runId)->exists()) {
            $status = Cache::get('cfb:pregame:worker-status', []);
            $this->recordOutcome('failed', 1, ($status['run_id'] ?? null) === $this->runId ? ($status['started_at'] ?? null) : null, null, $error?->getMessage());
        }
    }

    private function recordOutcome(string $state, int $code, ?string $started, ?string $output = null, ?string $error = null): void
    {
        $summary = Cache::get('cfb:pregame:run:'.$this->runId, ['publication_count' => 0, 'reused_count' => 0,
            'complete_count' => 0, 'held_count' => 0, 'failed_count' => 0, 'games' => []]);
        $status = [...$summary, 'run_id' => $this->runId, 'state' => $state, 'exit_code' => $code, 'arguments' => $this->arguments,
            'started_at' => $started, 'completed_at' => now()->toIso8601String(), 'error' => $error];
        Cache::put('cfb:pregame:worker-status', [...$status, 'output' => mb_strcut($output ?? '', -32768, null, 'UTF-8')], now()->addDay());
        if (! CommandHeartbeat::where('source', 'worker')->where('metadata->run_id', $this->runId)->exists()) {
            $command = 'cfb:run-pregame-pipeline --season='.($this->arguments['--season'] ?? 'current');
            $service = app(CommandHeartbeatService::class);
            if ($state === 'complete') {
                $service->recordSuccess($command, 'cfb', 'worker', $status);
            } else {
                $service->recordFailure($command, 'cfb', 'worker', $error ?? 'CFB worker outcome: '.$state, $status);
            }
        }
    }
}
