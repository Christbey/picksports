<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\Odds\MlbInningOddsSyncService;
use Illuminate\Console\Command;

class SyncInningOddsCommand extends Command
{
    protected $signature = 'mlb:sync-inning-odds
        {--days=1 : Number of calendar days ahead}
        {--markets= : Comma-separated inning market keys}
        {--bookmaker= : Odds API bookmaker key}
        {--dry-run : Fetch and report without persisting}';

    protected $description = 'Sync event-level first-inning, first-three, and first-five MLB odds';

    public function handle(MlbInningOddsSyncService $service): int
    {
        $markets = collect(explode(',', (string) $this->option('markets')))
            ->map(fn (string $market): string => trim($market))
            ->filter()
            ->values()
            ->all();
        $report = $service->sync(
            days: (int) $this->option('days'),
            markets: $markets,
            bookmaker: $this->option('bookmaker') ?: null,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            'MLB inning odds: %d/%d games updated, %d market rows, %d games missing event IDs.',
            $report['updated'],
            $report['games'],
            $report['market_rows'],
            $report['missing_event_ids'],
        ));

        return self::SUCCESS;
    }
}
