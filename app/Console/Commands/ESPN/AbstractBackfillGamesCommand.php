<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractBackfillGamesCommand extends Command
{
    use ResolvesJobClass;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const DEFAULT_STATUS = 'STATUS_SCHEDULED';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->requiredJobClass(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION');

        parent::__construct();
    }

    public function handle(): int
    {
        $targetStatus = (string) $this->option('status');
        $limit = (int) $this->option('limit');
        $sync = (bool) $this->option('sync');
        $date = $this->option('date');
        $date = $date !== null ? (string) $date : null;

        $games = $this->staleGames($targetStatus, $date, $limit);

        if ($games->isEmpty()) {
            $this->info($this->emptyMessage());

            return Command::SUCCESS;
        }

        $this->info($this->foundMessage($games->count()));
        $this->displayStatusBreakdown($games);
        $this->newLine();
        $this->info($sync ? 'Running synchronously...' : 'Dispatching game details sync jobs to queue...');

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $updated = 0;

        foreach ($games as $game) {
            $eventId = $this->eventIdFromGame($game);
            if ($sync) {
                if ($this->syncGameByEventId($eventId)) {
                    $updated++;
                }
            } else {
                $this->dispatchGameByEventId($eventId);
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

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--status=%s : Game status to target (or \"all\" for any non-final)}\n {--date= : Only backfill games on a specific date (Y-m-d)}\n {--limit=0 : Limit number of games to process (0 = all)}\n {--sync : Run synchronously instead of dispatching to queue}",
            $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME'),
            static::DEFAULT_STATUS
        );
    }

    protected function eventIdFromGame(Model $game): string
    {
        return (string) ($game->espn_event_id ?? '');
    }

    /**
     * @return Collection<int, Model>
     */
    abstract protected function staleGames(string $targetStatus, ?string $date, int $limit): Collection;

    abstract protected function syncGameByEventId(string $eventId): bool;

    abstract protected function dispatchGameByEventId(string $eventId): void;

    protected function emptyMessage(): string
    {
        return 'No stale past games found.';
    }

    protected function foundMessage(int $count): string
    {
        return "Found {$count} past games with non-final status.";
    }

    /**
     * @param  Collection<int, Model>  $games
     */
    protected function displayStatusBreakdown(Collection $games): void
    {
        $statusBreakdown = $games->groupBy('status')->map->count();

        foreach ($statusBreakdown as $status => $count) {
            $this->line("  {$status}: {$count}");
        }
    }
}
