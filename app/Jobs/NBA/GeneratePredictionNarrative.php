<?php

namespace App\Jobs\NBA;

use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\PredictionNarrativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePredictionNarrative implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $predictionId,
        public readonly bool $force = false
    ) {}

    public int $uniqueFor = 86400;

    public function uniqueId(): string
    {
        return implode(':', [
            $this->predictionId,
            $this->force ? 'force' : 'standard',
        ]);
    }

    public function handle(PredictionNarrativeService $narrativeService): void
    {
        $model = app(SportContextResolver::class)->resolve('nba')->models['prediction'];
        (new \App\Jobs\Predictions\GeneratePredictionNarrative($model, $this->predictionId, 'nba', $this->force))
            ->handle($narrativeService);
    }
}
