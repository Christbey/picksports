<?php

namespace App\Console\Commands\NBA;

use App\Jobs\Predictions\GeneratePredictionNarrative as GeneratePredictionNarrativeJob;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\BettingForecastSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;

class SyncPredictionNarrativesCommand extends Command
{
    protected $signature = 'nba:sync-prediction-narratives
                            {--game= : Regenerate narrative for a specific game ID}
                            {--from_date= : Start game date filter (YYYY-MM-DD)}
                            {--to_date= : End game date filter (YYYY-MM-DD)}
                            {--limit=500 : Max predictions to queue/process}
                            {--force : Regenerate even if narrative hash matches}
                            {--sync : Run immediately instead of queueing}';

    protected $description = 'Generate and persist NBA prediction narratives for API/view performance.';

    public function handle(): int
    {
        $canonical = app(BettingForecastSource::class)->usesCanonical('nba');
        $model = app(SportContextResolver::class)->resolve('nba')->models['prediction'];
        $query = $canonical
            ? app(CanonicalSportPredictionQuery::class)->queryForSport('nba', array_filter([
                'game_id' => $this->option('game'), 'from_date' => $this->option('from_date'), 'to_date' => $this->option('to_date'),
            ]))
            : $model::query()->with('game');
        if (! $canonical) {
            $this->applyFilters($query);
        }

        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $predictions = $query
            ->reorder()->orderByDesc($query->getModel()->qualifyColumn('updated_at'))
            ->limit($limit)
            ->get();

        if ($predictions->isEmpty()) {
            $this->warn('No NBA predictions matched the selected filters.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s narratives for %d NBA predictions...',
            $sync ? 'Generating' : 'Queueing',
            $predictions->count()
        ));

        $bar = $this->output->createProgressBar($predictions->count());
        $bar->start();

        foreach ($predictions as $prediction) {
            if ($sync) {
                Bus::dispatchSync(new GeneratePredictionNarrativeJob($prediction::class, (int) $prediction->id, 'nba', $force));
            } else {
                GeneratePredictionNarrativeJob::dispatch($prediction::class, (int) $prediction->id, 'nba', $force);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function applyFilters(Builder $query): void
    {
        $gameId = $this->option('game');
        if (is_scalar($gameId) && $gameId !== '') {
            $query->where('game_id', (int) $gameId);
        }

        $fromDate = $this->option('from_date');
        if (is_scalar($fromDate) && $fromDate !== '') {
            $query->whereHas('game', fn (Builder $q) => $q->whereDate('game_date', '>=', (string) $fromDate));
        }

        $toDate = $this->option('to_date');
        if (is_scalar($toDate) && $toDate !== '') {
            $query->whereHas('game', fn (Builder $q) => $q->whereDate('game_date', '<=', (string) $toDate));
        }
    }
}
