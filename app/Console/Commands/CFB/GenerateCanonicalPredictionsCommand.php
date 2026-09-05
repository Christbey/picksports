<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\CFB\Game;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'cfb:generate-canonical-predictions {--game=} {--season=} {--week=} {--date=} {--draft}';

    protected $description = 'Generate canonical CFB predictions';

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
        return 'CFB';
    }
}
