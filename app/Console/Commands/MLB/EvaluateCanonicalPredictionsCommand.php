<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\EvaluateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractEvaluateCanonicalPredictionsCommand;
use App\Models\MLB\Game;

class EvaluateCanonicalPredictionsCommand extends AbstractEvaluateCanonicalPredictionsCommand
{
    protected $signature = 'mlb:evaluate-canonical-predictions {--game=} {--season=}';

    protected $description = 'Evaluate canonical MLB predictions';

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
        return 'MLB';
    }
}
