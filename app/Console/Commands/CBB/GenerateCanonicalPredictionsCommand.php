<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCollegeBasketballCanonicalPredictionsCommand;
use App\Models\CBB\Game;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCollegeBasketballCanonicalPredictionsCommand
{
    protected $signature = 'cbb:generate-canonical-predictions
        {--game= : Generate for one CBB game ID}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--days-forward=8 : Bound default generation to the upcoming rollout horizon}
        {--draft : Create immutable revisions without publishing them}';

    protected $description = 'Generate canonical CBB predictions through the snapshot and calculation-release lifecycle';

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
        return 'CBB';
    }
}
