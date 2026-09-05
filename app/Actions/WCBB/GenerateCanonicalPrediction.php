<?php

namespace App\Actions\WCBB;

use App\Exceptions\Predictions\PredictionLifecycleException;
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
        $event = $game->sportEvent;

        if ($event === null) {
            throw new PredictionLifecycleException('WCBB canonical generation requires a linked sport event.');
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
