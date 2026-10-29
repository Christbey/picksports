<?php

namespace App\Actions\CBB;

use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Services\CBB\Predictions\CbbCalculator;
use App\Services\CBB\Predictions\CbbInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(
        private readonly PredictionLifecycleOrchestrator $orchestrator,
        private readonly CbbInputSnapshotBuilder $snapshotBuilder,
        private readonly CbbCalculator $calculator,
    ) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        return $this->orchestrator->generateForGame(
            $game, 'cbb', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
    }
}
