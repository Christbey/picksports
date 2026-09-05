<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\NFL\Game;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'nfl:generate-canonical-predictions {--game=} {--season=} {--date=} {--draft}';

    protected $description = 'Generate canonical NFL predictions';

    protected function gameClass(): string
    {
        return Game::class;
    }

    protected function generatorClass(): string
    {
        return GenerateCanonicalPrediction::class;
    }

    protected function sportLabel(): string
    {
        return 'NFL';
    }
}
