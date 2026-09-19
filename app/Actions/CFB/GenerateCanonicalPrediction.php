<?php

namespace App\Actions\CFB;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\CfbReleasedBetDecisionRecorder;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(private readonly PredictionLifecycleOrchestrator $orchestrator, private readonly CfbInputSnapshotBuilder $snapshotBuilder, private readonly CfbCalculator $calculator) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        if ($game->sportEvent === null) {
            throw new PredictionLifecycleException('CFB canonical generation requires a linked sport event.');
        }

        $prediction = $publish
            ? $this->orchestrator->generateAndPublish($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger)
            : $this->orchestrator->generate($game->sportEvent, $this->snapshotBuilder, $this->calculator, trigger: $trigger);
        if ($publish) {
            app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game);
        }

        return $prediction;
    }
}
