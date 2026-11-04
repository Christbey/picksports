<?php

namespace App\Actions\WCBB;

use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game;
use App\Services\Predictions\CanonicalTeamGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalTeamGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'wcbb');
    }
}
