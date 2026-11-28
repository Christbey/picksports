<?php

namespace App\Actions\NBA;

use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Services\NBA\Predictions\NbaCalculator;
use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\Predictions\Basketball\BasketballPolicyCalculator;
use App\Services\Predictions\Basketball\BasketballPolicyInputSnapshotBuilder;
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
        return $this->orchestrator->generateForGame(
            $game, 'nba', new BasketballPolicyInputSnapshotBuilder($this->snapshotBuilder, 'nba'), new BasketballPolicyCalculator($this->calculator, 'nba'), $publish, $trigger,
        );
    }
}
