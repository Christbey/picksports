<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncPlayerInjuries;
use App\Models\CBB\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FetchPlayerInjuries implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 110;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public array $backoff = [60, 180];

    public function uniqueId(): string
    {
        return $this->teamEspnId ?? 'all-teams';
    }

    public function __construct(
        public ?string $teamEspnId = null
    ) {}

    public function handle(): void
    {
        if ($this->teamEspnId === null) {
            $dispatched = 0;
            Team::query()->select(['id', 'espn_id'])->chunkById(100, function ($teams) use (&$dispatched): void {
                foreach ($teams as $team) {
                    if (! preg_match('/^[1-9]\d*$/', (string) $team->espn_id)) {
                        continue;
                    }
                    self::dispatch((string) $team->espn_id)->onConnection($this->connection)->onQueue($this->queue ?? 'default');
                    $dispatched++;
                }
            });
            Log::info('CBB: Dispatched team injury refreshes.', ['teams' => $dispatched]);

            return;
        }

        $started = hrtime(true);
        $outcome = 'failed';
        $count = null;
        try {
            $action = app(SyncPlayerInjuries::class);
            $count = $action->execute($this->teamEspnId);
            if (! $action->lastSyncReliable()) {
                throw new RuntimeException("CBB injury source unavailable or incomplete for team {$this->teamEspnId}.");
            }
            $outcome = 'completed';
        } finally {
            Log::log($outcome === 'completed' ? 'info' : 'warning', 'CBB: Team injury refresh finished.', [
                'team_espn_id' => $this->teamEspnId, 'outcome' => $outcome, 'injuries' => $count,
                'runtime_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'attempt' => $this->attempts(),
            ]);
        }
    }

    public function failed(?Throwable $error): void
    {
        Log::error('CBB: Team injury refresh failed.', ['team_espn_id' => $this->teamEspnId,
            'attempt' => $this->attempts(), 'error' => $error?->getMessage(), 'timeout_seconds' => $this->timeout]);
    }
}
