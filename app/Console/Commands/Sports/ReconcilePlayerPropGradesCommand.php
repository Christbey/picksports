<?php

namespace App\Console\Commands\Sports;

use App\Actions\GradePlayerProps;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePlayerPropGradesCommand extends Command
{
    protected $signature = 'sports:reconcile-player-prop-grades {sport : Provider sport key}
        {--ids= : Explicit comma-separated prop IDs, maximum 100}
        {--apply : Persist outcomes and append immutable correction receipts}
        {--dry-run : Inspect only; cannot be combined with --apply}';

    protected $description = 'Reconcile only explicitly selected props from current stored stats; dry-run by default';

    public function handle(GradePlayerProps $grader): int
    {
        $raw = (string) $this->option('ids');
        $ids = explode(',', $raw);
        if ($raw === '' || count($ids) > 100 || ($this->option('apply') && $this->option('dry-run'))
            || collect($ids)->contains(fn ($id) => preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1)) {
            $this->error('Supply 1–100 explicit positive prop IDs; --apply and --dry-run are mutually exclusive.');

            return self::FAILURE;
        }
        try {
            $grader->validateRepairScope((string) $this->argument('sport'), array_map('intval', array_unique($ids)));
        } catch (Throwable $exception) {
            $message = $exception instanceof \InvalidArgumentException ? $exception->getMessage() : $exception::class;
            $this->error('Entire repair scope refused before mutations: '.$message);

            return self::FAILURE;
        }
        $applied = 0;
        $failed = false;
        $changed = false;
        foreach (array_unique($ids) as $id) {
            try {
                $result = $grader->reconcileIds((string) $this->argument('sport'), [(int) $id], ! $this->option('apply'))[$id];
                $this->line('Prop '.$id.': '.$result['status'].($result['changed'] ? ($this->option('apply') ? ' (applied)' : ' (would change)') : ''));
                $failed = $failed || in_array($result['status'], ['not_found', 'entry_changed', 'identity_changed', 'missing_game'], true);
                $changed = $changed || $result['changed'];
                $applied += (int) ($this->option('apply') && $result['changed']);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error('Prop '.$id.' refused ('.$exception::class.').');
            }
        }
        if ($this->option('apply') && $changed) {
            app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
        }

        $this->info($applied.' prop revision(s) applied. Each ID is atomic; the batch is not atomic.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
