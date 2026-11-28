<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\PickCandidate;
use App\Services\MLB\Picks\MlbPickGradingService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePickSettlementsCommand extends Command
{
    protected $signature = 'mlb:reconcile-pick-settlements {--ids= : Required comma-separated candidate IDs, at most 100} {--apply : Persist current authoritative outcomes; default is read-only preview}';

    protected $description = 'Explicitly reconcile a bounded MLB candidate selection with audited settlement corrections';

    public function handle(MlbPickGradingService $grader): int
    {
        $raw = explode(',', (string) $this->option('ids'));
        if (count($raw) > 100 || collect($raw)->contains(fn ($id) => ! ctype_digit($id) || (int) $id < 1 || strlen($id) > 18)) {
            $this->error('--ids must contain 1 to 100 positive candidate IDs.');

            return self::FAILURE;
        }
        $ids = array_values(array_unique(array_map('intval', $raw)));
        $failed = 0;
        foreach ($ids as $id) {
            if (! $this->option('apply')) {
                $exists = PickCandidate::query()->whereKey($id)->exists();
                $this->line("Candidate {$id}: ".($exists ? 'selected; dry run, no outcome computed or written' : 'not found'));
                $failed += $exists ? 0 : 1;

                continue;
            }
            try {
                $receipt = $grader->gradeCandidate($id);
                if ($receipt === null) {
                    $this->warn("Candidate {$id}: not admitted; no outcome written.");
                    $failed++;
                } else {
                    $this->line("Candidate {$id}: {$receipt->result_status}; receipt {$receipt->id}.");
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Candidate {$id}: reconciliation failed.");
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
