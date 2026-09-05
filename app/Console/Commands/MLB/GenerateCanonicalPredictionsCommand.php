<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\MLB\Game;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'mlb:generate-canonical-predictions {--game=} {--season=} {--date=} {--draft}';

    protected $description = 'Generate canonical MLB predictions';

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
        return 'MLB';
    }
}
