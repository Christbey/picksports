<?php

namespace App\Actions\NFL;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Services\NFL\Predictions\NflCalculator;
use App\Services\NFL\Predictions\NflInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(private readonly PredictionLifecycleOrchestrator $orchestrator, private readonly NflInputSnapshotBuilder $snapshotBuilder, private readonly NflCalculator $calculator) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        if ($game->sportEvent === null) {
            throw new PredictionLifecycleException('NFL canonical generation requires a linked sport event.');
        }

        return $publish
            ? $this->orchestrator->generateAndPublish($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger)
            : $this->orchestrator->generate($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger);
    }
}
