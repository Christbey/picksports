<?php

namespace App\Actions\MLB;

use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Services\MLB\Predictions\MlbCalculator;
use App\Services\MLB\Predictions\MlbInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(private readonly PredictionLifecycleOrchestrator $orchestrator, private readonly MlbInputSnapshotBuilder $snapshotBuilder, private readonly MlbCalculator $calculator) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        return $this->orchestrator->generateForGame(
            $game, 'mlb', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
    }
}
