<?php

namespace App\Actions\WCBB;

use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game;
use App\Services\Predictions\Basketball\CanonicalBasketballGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalBasketballGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'wcbb');
    }
}
