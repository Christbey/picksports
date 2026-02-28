<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\IteratesDateRange;
use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Carbon\Carbon;
use Illuminate\Console\Command;

abstract class AbstractSyncGamesFromScoreboardCommand extends Command
{
    use IteratesDateRange;
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const SCOREBOARD_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} games from ESPN scoreboard API";

        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->supportsSeasonSync() && $this->option('season')) {
            return $this->syncSeason((int) $this->option('season'));
        }

        if ($fromDate = $this->option('from-date')) {
            $toDate = $this->option('to-date') ?? date('Y-m-d');

            return $this->syncDateRange((string) $fromDate, (string) $toDate);
        }

        $date = (string) ($this->argument('date') ?? date('Ymd'));
        $sport = $this->sportCode();

        $this->info("Dispatching {$sport} games scoreboard sync job for date {$date}...");

        $this->dispatchScoreboardSync($date);

        $this->info("{$sport} games scoreboard sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function supportsSeasonSync(): bool
    {
        return false;
    }

    protected function buildSignature(): string
    {
        $signature = sprintf(
            "%s\n {date? : The date in YYYYMMDD format (defaults to today)}\n {--from-date= : Start date in YYYY-MM-DD format}\n {--to-date= : End date in YYYY-MM-DD format}",
            $this->commandName()
        );

        $seasonOption = $this->seasonOptionSegment();
        if ($seasonOption !== '') {
            $signature .= "\n {$seasonOption}";
        }

        return $signature;
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    protected function seasonOptionSegment(): string
    {
        return '';
    }

    protected function syncSeason(int $season): int
    {
        [$startDate, $endDate] = $this->seasonDateRange($season);
        $sport = $this->sportCode();

        $this->info("Syncing full {$season} {$sport} season ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})...");

        if ($endDate->isFuture()) {
            $this->info('Note: Including future dates to capture scheduled games.');
        }

        return $this->syncDateRange($startDate->format('Y-m-d'), $endDate->format('Y-m-d'));
    }

    protected function syncDateRange(string $fromDate, string $toDate): int
    {
        $startDate = Carbon::parse($fromDate);
        $endDate = Carbon::parse($toDate);

        $totalDays = $this->inclusiveDayCount($startDate, $endDate);

        $this->info("Queuing {$totalDays} days of games ({$fromDate} to {$toDate})...");

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        $this->eachDateInRange($startDate, $endDate, function (Carbon $currentDate) use ($bar): void {
            $this->dispatchScoreboardSync($currentDate->format('Ymd'));
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Queued {$totalDays} game sync jobs successfully.");
        $this->info('Run "php artisan queue:work" to process the jobs.');

        return Command::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function seasonDateRange(int $season): array
    {
        throw new \LogicException('Season sync is not configured for this sport.');
    }

    protected function dispatchScoreboardSync(string $date): void
    {
        $job = $this->requiredJobClass(static::SCOREBOARD_SYNC_JOB_CLASS, 'SCOREBOARD_SYNC_JOB_CLASS');
        $job::dispatch($date);
    }
}
