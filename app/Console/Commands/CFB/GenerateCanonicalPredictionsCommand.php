<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\CFB\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'cfb:generate-canonical-predictions {--game=} {--season=} {--week=} {--date=} {--days-forward=} {--draft}';

    protected $description = 'Generate canonical CFB predictions';

    private bool $isolateGames = false;

    protected function configureBatch(int $count): void
    {
        $this->isolateGames = $count > 1 && blank($this->option('game'));
    }

    protected function generateGame(Model $game, object $generator): void
    {
        if (! $this->isolateGames) {
            parent::generateGame($game, $generator);

            return;
        }
        // Each game's large immutable evidence graph gets its own bounded process lifetime.
        // Keep children sequential; --game routes directly to calculation, preventing recursion.
        $command = [PHP_BINARY, base_path('artisan'), 'cfb:generate-canonical-predictions', '--game='.$game->getKey()];
        if ($this->option('draft')) {
            $command[] = '--draft';
        }
        $result = Process::path(base_path())->timeout(120)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput().' '.$result->output()) ?: 'Isolated forecast worker failed.');
        }
    }

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
