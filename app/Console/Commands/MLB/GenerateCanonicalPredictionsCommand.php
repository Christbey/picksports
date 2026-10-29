<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\MLB\Game;
use App\Services\MLB\MlbPeriodFeatureStore;
use App\Services\Predictions\PredictionDeliverySource;
use Illuminate\Database\Eloquent\Model;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'mlb:generate-canonical-predictions {--game=} {--season=} {--date=} {--days-forward=} {--draft}';

    protected $description = 'Generate canonical MLB predictions';

    /** @var list<int> */
    private array $generatedGameIds = [];

    public function handle(): int
    {
        $this->generatedGameIds = [];
        $status = parent::handle();
        if ($this->option('draft') || $this->generatedGameIds === [] || ! app(PredictionDeliverySource::class)->usesCanonical('mlb')) {
            return $status;
        }
        $games = Game::query()->whereKey($this->generatedGameIds)->where('status', 'STATUS_SCHEDULED')->get();
        $snapshots = app(MlbPeriodFeatureStore::class)->materialize($games);
        $this->info("Materialized {$snapshots->count()} MLB F3/F5 feature snapshots.");

        return $status;
    }

    protected function generateGame(Model $game, object $generator): void
    {
        parent::generateGame($game, $generator);
        $this->generatedGameIds[] = (int) $game->getKey();
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
        return 'MLB';
    }
}
