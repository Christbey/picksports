<?php

namespace App\Actions\WNBA;

use App\Exceptions\Predictions\PredictionLifecycleException;
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
        $event = $game->sportEvent;

        if ($event === null) {
            throw new PredictionLifecycleException('WNBA canonical generation requires a linked sport event.');
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
