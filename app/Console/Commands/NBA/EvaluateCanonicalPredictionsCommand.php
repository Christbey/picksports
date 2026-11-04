<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\NBA\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'nba:evaluate-canonical-predictions
        {--game= : Evaluate one NBA game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical NBA predictions';

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
        return 'NBA';
    }
}
