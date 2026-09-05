<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\NFL\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'nfl:evaluate-canonical-predictions {--game=} {--season=}';

    protected $description = 'Evaluate canonical NFL predictions';

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
        return 'NFL';
    }
}
