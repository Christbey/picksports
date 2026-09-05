<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\CFB\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'cfb:evaluate-canonical-predictions {--game=} {--season=} {--week=}';

    protected $description = 'Evaluate canonical CFB predictions';

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
        return 'CFB';
    }
}
