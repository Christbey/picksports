<?php

namespace App\Actions\MLB;

use App\Exceptions\Predictions\PredictionLifecycleException;
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
        if ($game->sportEvent === null) {
            throw new PredictionLifecycleException('MLB canonical generation requires a linked sport event.');
        }

        return $publish ? $this->orchestrator->generateAndPublish($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger) : $this->orchestrator->generate($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger);
    }
}
