<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPredictionTotalResultService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class BackfillTotalPickResultsCommand extends Command
{
    protected $signature = 'mlb:backfill-total-pick-results
        {--season= : Season to backfill}
        {--dry-run : Report what would change without writing}
        {--force : Recalculate rows that already have total pick results}';

    protected $description = 'Backfill stored MLB over/under pick results from prediction totals, market totals, and final scores.';

    public function handle(MlbPredictionTotalResultService $totals): int
    {
        if (! Schema::hasColumn('mlb_predictions', 'total_pick_result')) {
            $this->error('MLB total pick result columns are missing. Run migrations first.');

            return self::FAILURE;
        }

        $season = is_numeric($this->option('season')) ? (int) $this->option('season') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = $this->query($season, $force);
        $scanned = (clone $query)->count();
        $updated = 0;
        $skipped = 0;
        $samples = [];

        $query->with('game')
            ->orderBy('id')
            ->lazy()
            ->each(function (Prediction $prediction) use ($totals, $dryRun, &$updated, &$skipped, &$samples): void {
                if ($prediction->actual_total === null) {
                    $skipped++;

                    return;
                }

                $result = $totals->result($prediction, (float) $prediction->actual_total);

                if ($result['total_pick_result'] === null) {
                    $skipped++;

                    return;
                }

                $updated++;

                if (! $dryRun) {
                    $prediction->forceFill($result)->save();
                }

                if (count($samples) < 8) {
                    $samples[] = [
                        $prediction->id,
                        $prediction->game?->short_name,
                        $prediction->game?->game_date?->toDateString(),
                        $result['total_pick_side'],
                        $result['total_pick_line'],
                        $prediction->actual_total,
                        $result['total_pick_result'],
                    ];
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows scanned', $scanned],
                [$dryRun ? 'Rows that would update' : 'Rows updated', $updated],
                ['Rows skipped', $skipped],
            ]
        );

        if ($samples !== []) {
            $this->table(
                ['Prediction', 'Game', 'Date', 'Side', 'Line', 'Actual', 'Result'],
                $samples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<Prediction>
     */
    private function query(?int $season, bool $force): Builder
    {
        return Prediction::query()
            ->whereNotNull('graded_at')
            ->whereNotNull('actual_total')
            ->when($season !== null, fn (Builder $query): Builder => $query->where('season', $season))
            ->when(! $force, fn (Builder $query): Builder => $query->whereNull('total_pick_result'));
    }
}
