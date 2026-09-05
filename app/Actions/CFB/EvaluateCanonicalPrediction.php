<?php

namespace App\Actions\CFB;

use App\Models\CFB\Game;
use App\Models\PredictionEvaluation;
use App\Services\Predictions\CanonicalTeamGameEvaluator;

class EvaluateCanonicalPrediction
{
    public function __construct(private readonly CanonicalTeamGameEvaluator $evaluator) {}

    public function execute(Game $game): ?PredictionEvaluation
    {
        return $this->evaluator->evaluate($game, 'cfb');
    }
}
