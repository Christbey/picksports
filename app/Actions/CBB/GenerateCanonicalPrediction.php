<?php

namespace App\Actions\CBB;

use App\Exceptions\Predictions\PredictionLifecycleException;
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
        $event = $game->sportEvent;

        if ($event === null) {
            throw new PredictionLifecycleException('CBB canonical generation requires a linked sport event.');
        }

        if ($publish) {
            return $this->orchestrator->generateAndPublish(
                $event,
                $this->snapshotBuilder,
                $this->calculator,
                trigger: $trigger,
            );
        }

        return $this->orchestrator->generate(
            $event,
            $this->snapshotBuilder,
            $this->calculator,
            trigger: $trigger,
        );
    }
}
