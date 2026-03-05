<?php

namespace App\Console\Commands\NFL;

use App\Actions\ESPN\NFL\SyncGameDetails;
use App\Actions\ESPN\NFL\SyncPlayerStats;
use App\Actions\ESPN\NFL\SyncPlays;
use App\Actions\ESPN\NFL\SyncTeamStats;
use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Console\Command;
use Throwable;

class BackfillPlayerStatsCommand extends Command
{
    protected $signature = 'nfl:backfill-player-stats
        {--season= : Limit to season (e.g. 2025)}
        {--date= : Limit to a game date (Y-m-d)}
        {--limit=0 : Number of final games to process (0 = all)}
        {--sync : Run synchronously instead of dispatching queue jobs}';

    protected $description = 'Backfill NFL player stats by re-syncing ESPN game details for final games';

    public function handle(): int
    {
        $season = $this->option('season');
        $date = $this->option('date');
        $limit = max(0, (int) $this->option('limit'));
        $sync = (bool) $this->option('sync');

        $query = Game::query()
            ->where('status', config('nfl.statuses.final'))
            ->whereNotNull('espn_event_id')
            ->orderBy('game_date', 'desc');

        if ($season !== null && $season !== '') {
            $query->where('season', (int) $season);
        }

        if ($date !== null && $date !== '') {
            $query->whereDate('game_date', (string) $date);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->info('No matching final NFL games found for backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$games->count()} final NFL games to backfill.");
        $this->info($sync ? 'Running synchronously...' : 'Dispatching ESPN game-details jobs...');

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $updated = 0;
        $failed = 0;

        if ($sync) {
            $service = new EspnService;
            $syncPlayerStats = new SyncPlayerStats;
            $syncTeamStats = new SyncTeamStats;
            $syncPlays = new SyncPlays($service);
            $syncAction = new SyncGameDetails($service, $syncPlayerStats, $syncTeamStats, $syncPlays);
        }

        foreach ($games as $game) {
            $eventId = (string) $game->espn_event_id;

            try {
                if ($sync) {
                    $result = $syncAction->execute($eventId);
                    if ((int) ($result['player_stats'] ?? 0) > 0) {
                        $updated++;
                    }
                } else {
                    FetchGameDetails::dispatch($eventId);
                }
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Failed event {$eventId}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info("Backfill complete. Updated player stats for {$updated} games.");
            if ($failed > 0) {
                $this->warn("Failed games: {$failed}");
            }
        } else {
            $this->info("Dispatched {$games->count()} backfill jobs.");
            $this->info('Run a worker to process jobs: php artisan queue:work');
        }

        return self::SUCCESS;
    }
}
