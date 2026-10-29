<?php

namespace App\Jobs\Predictions;

use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Predictions\PredictionNarrativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class GeneratePredictionNarrative implements ShouldBeUnique, ShouldQueue
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

    public int $uniqueFor = 86400;

    public function uniqueId(): string
    {
        return implode(':', [
            $this->predictionModelClass,
            $this->predictionId,
            $this->sport,
            $this->force ? 'force' : 'standard',
        ]);
    }

    public function handle(PredictionNarrativeService $narrativeService): void
    {
        $expected = app(SportContextResolver::class)->resolve($this->sport)->models['prediction'];
        if (! in_array($this->predictionModelClass, [$expected, CanonicalPrediction::class], true)) {
            return;
        }
        if ($this->predictionModelClass === CanonicalPrediction::class) {
            if (! app(BettingForecastSource::class)->usesCanonical($this->sport)) {
                return;
            }
            $prediction = app(CanonicalSportPredictionQuery::class)->queryForSport($this->sport)
                ->whereKey($this->predictionId)->first();
            if ($prediction === null) {
                return;
            }
            $hash = $narrativeService->inputHashForSport($prediction, null, $this->sport);
            $key = 'prediction-narrative:canonical:'.$prediction->public_id.':'.$hash;
            if ($this->force || ! Cache::has($key)) {
                $narrative = $narrativeService->forSport($prediction, null, $this->sport, true);
                if (app(CanonicalSportPredictionQuery::class)->queryForSport($this->sport)->whereKey($prediction->id)->exists()) {
                    Cache::put($key, $narrative, now()->addDay());
                }
            }

            return;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $expected;
        $prediction = $modelClass::query()->with(['game.homeTeam', 'game.awayTeam'])->find($this->predictionId);
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
        $narrative = $this->sport === 'nba'
            ? $narrativeService->forNba($prediction, $prediction->game, true, $narrativeService->buildNbaTrendSnapshot($prediction->game))
            : $narrativeService->forSport($prediction, $prediction->game, $this->sport, true);
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
