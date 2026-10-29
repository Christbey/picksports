<?php

namespace App\Actions\NFL;

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Services\NFL\Predictions\NflCalculator;
use App\Services\NFL\Predictions\NflCanonicalDecisionRecorder;
use App\Services\NFL\Predictions\NflInputSnapshotBuilder;
use App\Services\Predictions\PredictionLifecycleOrchestrator;

class GenerateCanonicalPrediction
{
    public function __construct(private readonly PredictionLifecycleOrchestrator $orchestrator, private readonly NflInputSnapshotBuilder $snapshotBuilder, private readonly NflCalculator $calculator) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        $prediction = $this->orchestrator->generateForGame(
            $game, 'nfl', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
        if ($publish) {
            app(NflCanonicalDecisionRecorder::class)->record($prediction, $game);
        }

        return $prediction;
    }
}
