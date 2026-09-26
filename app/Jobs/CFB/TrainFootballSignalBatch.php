<?php

namespace App\Jobs\CFB;

use App\Models\CalculationRelease;
use App\Services\CFB\Signals\CfbFootballSignalArtifactStore;
use App\Services\CFB\Signals\CfbFootballSignalHistoricalTrainer;
use App\Services\CommandHeartbeatService;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TrainFootballSignalBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 25;

    public function __construct(public int $releaseId, public int $fromSeason, public int $toSeason, public string $requestedAt)
    {
        $this->onQueue('sync');
    }

    public function handle(CfbFootballSignalHistoricalTrainer $trainer, CfbFootballSignalArtifactStore $store): void
    {
        $lock = Cache::lock('cfb:signal-training', 150);
        if (! $lock->get()) {
            $this->release(155);

            return;
        }
        $next = false;
        try {
            $release = CalculationRelease::findOrFail($this->releaseId);
            $existing = $store->load($release->configuration);
            if ($existing && CarbonImmutable::parse($existing['available_at'])->gte(CarbonImmutable::parse($this->requestedAt))) {
                return;
            }
            $artifact = $trainer->train($release->configuration, $this->fromSeason, $this->toSeason, CarbonImmutable::now(), maxGames: 250);
            if (($artifact['complete'] ?? true) === false) {
                $next = true;
            } else {
                Cache::forget('cfb:football-signal-evidence:'.app(CanonicalPayloadHasher::class)->hash($release->configuration).':'.$release->semantic_version);
                app(CommandHeartbeatService::class)->recordSuccess('cfb:train-football-signals', 'cfb', 'queue', [
                    'release_id' => $release->id, 'games' => count($artifact['source_game_ids']), 'complete' => true]);
            }
        } finally {
            $lock->release();
        }
        if ($next) {
            self::dispatch($this->releaseId, $this->fromSeason, $this->toSeason, $this->requestedAt);
        }
    }

    public function failed(?Throwable $error): void
    {
        app(CommandHeartbeatService::class)->recordFailure('cfb:train-football-signals', 'cfb', 'queue',
            'Signal training batch failed; durable checkpoint retained.', ['release_id' => $this->releaseId, 'error_type' => $error ? get_class($error) : null]);
    }
}
