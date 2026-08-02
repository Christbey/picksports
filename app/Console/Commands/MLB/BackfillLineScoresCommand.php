<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbLineScoreBackfillService;
use Illuminate\Console\Command;

class BackfillLineScoresCommand extends Command
{
    protected $signature = 'mlb:backfill-linescores
        {--season= : MLB season year}
        {--from-date= : Earliest local game date}
        {--to-date= : Latest local game date}
        {--lookback-days= : Limit the repair to this many calendar days}
        {--sleep-ms=100 : Delay between ESPN scoreboard dates}
        {--dry-run : Fetch and report without updating games}';

    protected $description = 'Backfill missing MLB inning line scores from ESPN daily scoreboards';

    public function handle(MlbLineScoreBackfillService $service): int
    {
        $season = (int) ($this->option('season') ?: now()->year);
        $fromDate = $this->option('from-date') ?: null;
        $toDate = $this->option('to-date') ?: null;
        $lookbackDays = $this->option('lookback-days');

        if ($lookbackDays !== null && $lookbackDays !== '') {
            $fromDate = now()->subDays(max(0, (int) $lookbackDays))->toDateString();
            $toDate ??= now()->toDateString();
        }

        $report = $service->backfill(
            season: $season,
            fromDate: $fromDate,
            toDate: $toDate,
            dryRun: (bool) $this->option('dry-run'),
            sleepMilliseconds: max(0, (int) $this->option('sleep-ms')),
        );

        $this->info(sprintf(
            'MLB line scores: %d/%d games updated across %d dates; %d event fallbacks; %d unmatched; %d date requests failed.',
            $report['updated'],
            $report['games'],
            $report['dates'],
            $report['event_fallbacks'],
            $report['unmatched'],
            $report['failed_dates'],
        ));

        return self::SUCCESS;
    }
}
