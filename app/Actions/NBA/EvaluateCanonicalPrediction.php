<?php

namespace App\Actions\NBA;

use App\Models\NBA\Game;
use App\Models\PredictionEvaluation;
use App\Services\Predictions\Basketball\CanonicalBasketballGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalBasketballGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'nba');
    }
}
