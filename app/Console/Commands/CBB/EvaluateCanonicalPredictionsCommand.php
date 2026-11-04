<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\CBB\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'cbb:evaluate-canonical-predictions
        {--game= : Evaluate one CBB game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical CBB predictions';

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
        return 'CBB';
    }
}
