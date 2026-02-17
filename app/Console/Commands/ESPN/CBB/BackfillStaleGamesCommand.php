<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncGameDetails;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use Illuminate\Console\Command;

class BackfillStaleGamesCommand extends Command
{
    protected $signature = 'espn:backfill-cbb-stale-games
                            {--status=STATUS_SCHEDULED : Game status to target (or "all" for any non-final)}
                            {--date= : Only backfill games on a specific date (Y-m-d)}
                            {--limit=0 : Limit number of games to process (0 = all)}
                            {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Backfill past CBB games stuck in non-final status by fetching details from ESPN';

    public function handle(SyncGameDetails $syncGameDetails): int
    {
        $targetStatus = $this->option('status');
        $limit = (int) $this->option('limit');
        $sync = $this->option('sync');
        $date = $this->option('date');

        $query = Game::query()
            ->where('game_date', '<', now()->format('Y-m-d'))
            ->whereNotIn('status', ['STATUS_FINAL', 'STATUS_FULL_TIME'])
            ->whereNotNull('espn_event_id')
            ->orderBy('game_date', 'asc');

        if ($targetStatus !== 'all') {
            $query->where('status', $targetStatus);
        }

        if ($date) {
            $query->where('game_date', $date);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->info('No stale past games found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$games->count()} past games with non-final status.");

        $statusBreakdown = $games->groupBy('status')->map->count();
        foreach ($statusBreakdown as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $this->newLine();

        if ($sync) {
            $this->info('Running synchronously...');
        } else {
            $this->info('Dispatching game details sync jobs to queue...');
        }

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $updated = 0;

        foreach ($games as $game) {
            if ($sync) {
                $result = $syncGameDetails->execute($game->espn_event_id);
                if ($result['game_updated']) {
                    $updated++;
                }
            } else {
                FetchGameDetails::dispatch($game->espn_event_id);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info("Updated {$updated} of {$games->count()} games.");
        } else {
            $this->info("Dispatched {$games->count()} game details sync jobs.");
            $this->info('Run a queue worker to process: php artisan queue:work');
        }

        return Command::SUCCESS;
    }
}
