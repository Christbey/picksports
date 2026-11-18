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

    public int $timeout = self::TIMEOUT_SECONDS;

    public const TIMEOUT_SECONDS = 1700;

    public const BATCH_SIZE = 5;

    public const BATCH_PENDING = 75;

    public const BATCH_BUSY = 76;

    // Redis can redeliver a reservation after a worker is replaced.
    // Completed command failures still fail explicitly.
    public int $tries = 2;

    public int $uniqueFor = 7200;

    public string $runId;

    public function __construct(public array $arguments, public ?int $batchOffset = null, ?string $runId = null)
    {
        $this->runId = $runId ?? (string) Str::uuid();
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        if ($this->batchOffset !== null) {
            return 'cfb-pregame-batch-'.$this->runId.'-'.$this->batchOffset;
        }

        return 'cfb-pregame-'.($this->arguments['--season'] ?? 'current');
    }

    public function handle(): void
    {
        $this->runId ??= (string) Str::uuid();
        if (CommandHeartbeat::where('source', 'worker')->where('metadata->run_id', $this->runId)->exists()) {
            return;
        }
        $started = now()->toIso8601String();
        $monotonicStart = hrtime(true);
        $this->storeStatus(['state' => 'running', 'arguments' => $this->arguments, 'started_at' => $started], true);
        try {
            $batch = match (true) {
                $this->batchOffset !== null => ['--batch-offset' => $this->batchOffset],
                isset($this->arguments['--heartbeat-name']) && ($this->arguments['--resume'] ?? false) => ['--batch-forecasts' => true],
                default => [],
            };
            $code = Artisan::call('cfb:run-pregame-pipeline', [...$this->arguments, '--run-id' => $this->runId, ...$batch]);
            if ($code === self::BATCH_BUSY) {
                return;
            }
            if ($code === self::BATCH_PENDING) {
                $nextOffset = $this->batchOffset === null ? 0 : $this->batchOffset + self::BATCH_SIZE;
                self::dispatch($this->arguments, $nextOffset, $this->runId);

                return;
            }
        } catch (Throwable $error) {
            $this->recordOutcome('failed', 1, $started, null, $error->getMessage(), $monotonicStart);
            $this->clearBatch();
            $this->fail($error);

            return;
        }
        $state = $code === 0 ? 'complete' : ($code === 2 ? 'partial' : 'failed');
        $this->recordOutcome($state, $code, $started, Artisan::output(), monotonicStart: $monotonicStart);
        $this->clearBatch();
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

    private function clearBatch(): void
    {
        if (Cache::get('cfb:pregame:active-batch') === $this->runId) {
            Cache::forget('cfb:pregame:active-batch');
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->runId ??= (string) Str::uuid();
        $this->clearBatch();
        if (! CommandHeartbeat::where('source', 'worker')->where('metadata->run_id', $this->runId)->exists()) {
            $status = Cache::get('cfb:pregame:worker-status', []);
            $this->recordOutcome('failed', 1, ($status['run_id'] ?? null) === $this->runId ? ($status['started_at'] ?? null) : null, null, $error?->getMessage());
        }
    }

    private function recordOutcome(string $state, int $code, ?string $started, ?string $output = null, ?string $error = null, ?int $monotonicStart = null): void
    {
        $summary = Cache::get('cfb:pregame:run:'.$this->runId, ['publication_count' => 0, 'reused_count' => 0,
            'complete_count' => 0, 'held_count' => 0, 'failed_count' => 0, 'games' => []]);
        $status = [...$summary, 'run_id' => $this->runId, 'state' => $state, 'exit_code' => $code, 'arguments' => $this->arguments,
            'started_at' => $started, 'completed_at' => now()->toIso8601String(), 'error' => $error];
        if ($monotonicStart !== null) {
            $status['duration_ms'] = (int) ((hrtime(true) - $monotonicStart) / 1_000_000);
            $status['peak_memory_bytes'] = memory_get_peak_usage(true);
        }
        $this->storeStatus([...$status, 'output' => mb_strcut($output ?? '', -32768, null, 'UTF-8')]);
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
    private function storeStatus(array $values, bool $starting = false): void
    {
        Cache::lock('cfb:pregame:worker-status-lock', 10)->block(5, function () use ($values, $starting): void {
            $status = Cache::get('cfb:pregame:worker-status', []);
            if (! $starting && ($status['run_id'] ?? null) !== $this->runId) {
                return;
            }
            Cache::put('cfb:pregame:worker-status', [...($starting ? [] : $status), ...$values,
                'run_id' => $this->runId], now()->addDay());
        });
    }
}
