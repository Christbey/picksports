<?php

namespace App\Actions\CFB;

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
        $prediction = $this->orchestrator->generateForGame(
            $game, 'cfb', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );

        if ($publish) {
            app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game);
        }

        return $prediction;
    }
}
