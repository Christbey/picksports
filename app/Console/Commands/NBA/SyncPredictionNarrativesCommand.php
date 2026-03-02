<?php

namespace App\Console\Commands\NBA;

use App\Jobs\NBA\GeneratePredictionNarrative as GeneratePredictionNarrativeJob;
use App\Models\NBA\Prediction;
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
        $query = Prediction::query()->with('game');

        $this->applyFilters($query);

        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $predictions = $query
            ->orderByDesc('updated_at')
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
                Bus::dispatchSync(new GeneratePredictionNarrativeJob((int) $prediction->id, $force));
            } else {
                GeneratePredictionNarrativeJob::dispatch((int) $prediction->id, $force);
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
