<?php

namespace App\Console\Commands\MLB;

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\GradePredictions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class OperationsSentinelCommand extends Command
{
    protected $signature = 'mlb:operations-sentinel
        {--from-date= : Start date in YYYY-MM-DD format, defaults to yesterday}
        {--to-date= : End date in YYYY-MM-DD format, defaults to tomorrow}
        {--season= : MLB season to grade, defaults to current year}
        {--skip-validation : Skip the final validation run}';

    protected $description = 'Run the daily MLB operations sentinel: sync recent scoreboards, grade final predictions, and validate data health';

    public function handle(SyncGamesFromScoreboard $syncGames, GradePredictions $gradePredictions): int
    {
        $fromDate = CarbonImmutable::parse($this->option('from-date') ?: now()->subDay()->toDateString())->startOfDay();
        $toDate = CarbonImmutable::parse($this->option('to-date') ?: now()->addDay()->toDateString())->startOfDay();
        $season = (int) ($this->option('season') ?: now()->year);

        if ($toDate->lt($fromDate)) {
            $this->error('--to-date must be on or after --from-date.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Running MLB operations sentinel for %s through %s.',
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ));

        $synced = 0;
        for ($date = $fromDate; $date->lte($toDate); $date = $date->addDay()) {
            $scoreboardDate = $date->format('Ymd');
            $this->line("Syncing MLB scoreboard {$scoreboardDate}...");
            $synced += $syncGames->execute($scoreboardDate);
        }

        $this->info("Synced {$synced} MLB game row update(s).");

        $grading = $gradePredictions->execute($season);
        $this->info(sprintf(
            'Graded %d MLB prediction(s) for season %d.',
            (int) ($grading['graded'] ?? 0),
            $season,
        ));

        if ($this->option('skip-validation')) {
            return self::SUCCESS;
        }

        $this->info('Running MLB validation after sentinel repair pass...');
        $validationExitCode = Artisan::call('healthcheck:validate-data', [
            '--sport' => 'mlb',
        ]);

        $this->output->write(Artisan::output());

        return $validationExitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
