<?php

namespace App\Actions\WCBB;

use App\Models\CanonicalPrediction;
use App\Models\WCBB\Game;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\WCBB\Predictions\WcbbCalculator;
use App\Services\WCBB\Predictions\WcbbInputSnapshotBuilder;
use App\Services\WCBB\Predictions\WcbbSpreadTracking;

class GenerateCanonicalPrediction
{
    public function __construct(
        private readonly PredictionLifecycleOrchestrator $orchestrator,
        private readonly WcbbInputSnapshotBuilder $snapshotBuilder,
        private readonly WcbbCalculator $calculator,
        private readonly WcbbSpreadTracking $spreadTracking,
    ) {}

    public function execute(Game $game, bool $publish = true, string $trigger = 'scheduled'): CanonicalPrediction
    {
        $prediction = $this->orchestrator->generateForGame(
            $game, 'wcbb', $this->snapshotBuilder, $this->calculator, $publish, $trigger,
        );
        if ($publish) {
            $this->spreadTracking->record($prediction, $game);
        }

        return $prediction;
    }
}
