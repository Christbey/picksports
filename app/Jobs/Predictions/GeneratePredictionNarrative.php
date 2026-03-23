<?php

namespace App\Jobs\Predictions;

use App\Services\Predictions\PredictionNarrativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePredictionNarrative implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $predictionModelClass,
        public readonly int $predictionId,
        public readonly string $sport,
        public readonly bool $force = false
    ) {}

    public function handle(PredictionNarrativeService $narrativeService): void
    {
        if (! class_exists($this->predictionModelClass)) {
            return;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->predictionModelClass;

        /** @var Model|null $prediction */
        $prediction = $modelClass::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->find($this->predictionId);

        if (! $prediction || ! $prediction->game) {
            return;
        }

        $currentHash = $narrativeService->inputHashForSport($prediction, $prediction->game, $this->sport);

        if (
            ! $this->force
            && is_array($prediction->narrative_json)
            && $prediction->narrative_input_hash === $currentHash
        ) {
            return;
        }

        $startedAt = microtime(true);
        $narrative = $narrativeService->forSport($prediction, $prediction->game, $this->sport, true);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $generatedBy = (string) ($narrative['generated_by'] ?? '');
        $provider = null;
        $model = null;

        if ($generatedBy !== '') {
            $parts = explode(':', $generatedBy, 2);
            $provider = $parts[0] ?? null;
            $model = $parts[1] ?? ($generatedBy !== '' ? $generatedBy : null);
        }

        $prediction->forceFill([
            'narrative_json' => $narrative,
            'narrative_provider' => $provider,
            'narrative_model' => $model,
            'narrative_input_hash' => $currentHash,
            'narrative_latency_ms' => $latencyMs,
            'narrative_generated_at' => now(),
        ])->saveQuietly();
    }
}
