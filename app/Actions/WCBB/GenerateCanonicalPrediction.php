<?php

namespace App\Actions\WCBB;

use App\Models\CanonicalPrediction;
use App\Models\WCBB\Game;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\WCBB\Predictions\WcbbCalculator;
use App\Services\WCBB\Predictions\WcbbInputSnapshotBuilder;

class GenerateCanonicalPrediction
{
    public function __construct(
        private readonly PredictionLifecycleOrchestrator $orchestrator,
        private readonly WcbbInputSnapshotBuilder $snapshotBuilder,
        private readonly WcbbCalculator $calculator,
    ) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        return $this->orchestrator->generateForGame(
            $game, 'wcbb', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
    }
}
