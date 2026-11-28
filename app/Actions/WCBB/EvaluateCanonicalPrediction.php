<?php

namespace App\Actions\WCBB;

use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game;
use App\Services\Predictions\CanonicalTeamGameEvaluator;
use App\Services\WCBB\Predictions\WcbbSpreadTracking;

class EvaluateCanonicalPrediction
{
    public function __construct(
        private readonly CanonicalTeamGameEvaluator $evaluator,
        private readonly WcbbSpreadTracking $spreadTracking,
    ) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        $evaluation = $this->evaluator->evaluate($game, 'wcbb');
        if ($evaluation !== null) {
            $this->spreadTracking->settle($game, $evaluation);
        }

        return $evaluation;
    }
}
