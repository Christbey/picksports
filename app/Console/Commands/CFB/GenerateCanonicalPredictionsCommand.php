<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\CFB\Game;
use Illuminate\Database\Eloquent\Model;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'cfb:generate-canonical-predictions {--game=} {--season=} {--week=} {--date=} {--days-forward=} {--draft}';

    protected $description = 'Generate canonical CFB predictions';

    protected function releaseGameResources(Model $game): void
    {
        // Immutable prediction graphs are large; release them between games in a long-running CLI batch.
        $game->unsetRelations();
        gc_collect_cycles();
    }

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
