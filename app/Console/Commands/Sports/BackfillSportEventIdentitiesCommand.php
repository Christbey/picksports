<?php

namespace App\Console\Commands\Sports;

use App\Services\Sports\SportEventIdentityBackfill;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class BackfillSportEventIdentitiesCommand extends Command implements Isolatable
{
    protected $signature = 'sports:backfill-event-identities
        {--sport=* : Sport keys to backfill (defaults to all)}
        {--chunk=500 : Number of games to process per database chunk}
        {--limit=0 : Maximum games to process across all sports}
        {--dry-run : Report the changes without writing them}';

    protected $description = 'Create canonical sport event identities and link legacy sport game detail rows';

    public function handle(SportEventIdentityBackfill $backfill): int
    {
        $sports = collect((array) $this->option('sport'))
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values();
        $sports = $sports->isEmpty() ? collect($backfill->supportedSports()) : $sports;
        $unsupported = $sports->diff($backfill->supportedSports());

        if ($unsupported->isNotEmpty()) {
            $this->error('Unsupported sport(s): '.$unsupported->implode(', ').'.');

            return self::FAILURE;
        }

        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 5000) {
            $this->error('The --chunk option must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        if ($limit === false || $limit < 0) {
            $this->error('The --limit option must be a non-negative integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $backfill->backfill($sports->all(), $chunkSize, $limit, $dryRun);

        $this->info($dryRun
            ? 'Sport event identity backfill dry run completed.'
            : 'Sport event identity backfill completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Games scanned', $report['games_scanned']],
                [$dryRun ? 'Events that would be created' : 'Events created', $report['events_created']],
                [$dryRun ? 'Mappings that would be created' : 'Mappings created', $report['mappings_created']],
                [$dryRun ? 'Games that would be linked' : 'Games linked', $report['games_linked']],
                ['Games already linked', $report['already_linked']],
                ['Conflicts skipped', $report['conflicts']],
            ],
        );

        if ($report['conflicts'] > 0) {
            $this->warn('Conflicting identities were left unchanged for manual review.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
