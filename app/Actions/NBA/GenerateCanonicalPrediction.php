<?php

namespace App\Actions\NBA;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Services\NBA\Predictions\NbaCalculator;
use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(
        private readonly PredictionLifecycleOrchestrator $orchestrator,
        private readonly NbaInputSnapshotBuilder $snapshotBuilder,
        private readonly NbaCalculator $calculator,
    ) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        $event = $game->sportEvent;

        if ($event === null) {
            throw new PredictionLifecycleException('NBA canonical generation requires a linked sport event.');
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
