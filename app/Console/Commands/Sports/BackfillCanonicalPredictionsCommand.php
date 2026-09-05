<?php

namespace App\Console\Commands\Sports;

use App\Services\Predictions\CanonicalPredictionSyncService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class BackfillCanonicalPredictionsCommand extends Command implements Isolatable
{
    protected $signature = 'sports:backfill-canonical-predictions
        {--sport=* : Sport keys to backfill (defaults to all)}
        {--chunk=500 : Number of legacy predictions per database chunk}
        {--limit=0 : Maximum legacy predictions to scan across all sports}
        {--dry-run : Report changes and conflicts without writing}';

    protected $description = 'Create canonical predictions and market projections from legacy sport prediction rows';

    public function handle(CanonicalPredictionSyncService $sync): int
    {
        $sports = collect((array) $this->option('sport'))
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values();
        $sports = $sports->isEmpty() ? collect($sync->supportedSports()) : $sports;
        $unsupported = $sports->diff($sync->supportedSports());

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
        $report = $sync->backfill($sports->all(), $chunkSize, $limit, $dryRun);

        $this->info($dryRun
            ? 'Canonical prediction backfill dry run completed.'
            : 'Canonical prediction backfill completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy predictions scanned', $report['predictions_scanned']],
                [$dryRun ? 'Predictions that would be created' : 'Predictions created', $report['predictions_created']],
                [$dryRun ? 'Predictions that would be updated' : 'Predictions updated', $report['predictions_updated']],
                ['Predictions already synchronized', $report['already_synced']],
                [$dryRun ? 'Markets that would be created' : 'Markets created', $report['markets_created']],
                [$dryRun ? 'Markets that would be updated' : 'Markets updated', $report['markets_updated']],
                [$dryRun ? 'Markets that would be deactivated' : 'Markets deactivated', $report['markets_deactivated']],
                ['Missing canonical events', $report['missing_events']],
                ['Conflicts skipped', $report['conflicts']],
            ],
        );

        if ($report['conflict_details'] !== []) {
            $this->warn('Conflicting prediction identities were left unchanged:');
            $this->table(
                ['Sport', 'Source', 'Detail ID', 'Reason'],
                collect($report['conflict_details'])
                    ->map(fn (array $conflict): array => [
                        $conflict['sport'],
                        $conflict['detail_source'],
                        $conflict['detail_id'],
                        $conflict['reason'],
                    ])
                    ->all(),
            );

            return self::FAILURE;
        }

        if ($report['missing_events'] > 0) {
            $this->warn('Some predictions were skipped because their games do not have canonical sport events.');
        }

        return self::SUCCESS;
    }
}
