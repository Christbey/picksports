<?php

namespace App\Console\Commands\CBB;

use App\Services\CBB\Predictions\CbbCanonicalCutoverReadinessService;
use Illuminate\Console\Command;

class ReportCanonicalCutoverReadinessCommand extends Command
{
    protected $signature = 'cbb:report-canonical-cutover-readiness
        {--season= : Limit the report to a CBB season}
        {--json : Emit machine-readable JSON}
        {--fail-on-not-ready : Return a failure code when cutover requirements are incomplete}';

    protected $description = 'Report whether CBB canonical predictions and evaluations are ready for API cutover';

    public function handle(CbbCanonicalCutoverReadinessService $readiness): int
    {
        $report = $readiness->report(filled($this->option('season')) ? (int) $this->option('season') : null);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Value'], collect($report)->map(fn (mixed $value, string $key): array => [
                $key,
                is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'all'),
            ])->values()->all());
        }

        return $this->option('fail-on-not-ready') && ! $report['ready_for_cutover']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
