<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionEvaluation;
use App\Services\Predictions\CanonicalTeamGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalTeamGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'nfl');
    }
}
