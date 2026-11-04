<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\WCBB\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'wcbb:evaluate-canonical-predictions
        {--game= : Evaluate one WCBB game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical WCBB predictions';

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
        return 'WCBB';
    }
}
