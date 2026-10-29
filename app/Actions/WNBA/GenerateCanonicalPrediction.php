<?php

namespace App\Actions\WNBA;

use App\Models\CanonicalPrediction;
use App\Models\WNBA\Game;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\WNBA\Predictions\WnbaCalculator;
use App\Services\WNBA\Predictions\WnbaInputSnapshotBuilder;

class GenerateCanonicalPrediction
{
    public function __construct(
        private readonly PredictionLifecycleOrchestrator $orchestrator,
        private readonly WnbaInputSnapshotBuilder $snapshotBuilder,
        private readonly WnbaCalculator $calculator,
    ) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        return $this->orchestrator->generateForGame(
            $game, 'wnba', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
    }
}
