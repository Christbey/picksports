<?php

namespace App\Actions\WNBA;

use App\Models\PredictionEvaluation;
use App\Models\WNBA\Game;
use App\Services\Predictions\Basketball\CanonicalBasketballGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalBasketballGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'wnba');
    }
}
