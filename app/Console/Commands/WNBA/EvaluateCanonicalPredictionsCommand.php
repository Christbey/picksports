<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\WNBA\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'wnba:evaluate-canonical-predictions
        {--game= : Evaluate one WNBA game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical WNBA predictions';

    protected function gameClass(): string
    {
        return Game::class;
    }

    protected function evaluatorClass(): string
    {
        return EvaluateCanonicalPrediction::class;
    }

    protected function sportLabel(): string
    {
        return 'WNBA';
    }
}
