<?php

namespace App\Console\Commands\WNBA;

use App\Services\WNBA\Predictions\WnbaCanonicalCutoverReadinessService;
use Illuminate\Console\Command;

class ReportCanonicalCutoverReadinessCommand extends Command
{
    protected $signature = 'wnba:report-canonical-cutover-readiness
        {--season= : Limit the report to a WNBA season}
        {--json : Emit machine-readable JSON}
        {--fail-on-not-ready : Return a failure code when cutover requirements are incomplete}';

    protected $description = 'Report whether WNBA canonical predictions and evaluations are ready for API cutover';

    public function handle(WnbaCanonicalCutoverReadinessService $readiness): int
    {
        $report = $readiness->report(
            filled($this->option('season')) ? (int) $this->option('season') : null,
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Check', 'Value'],
                collect($report)->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'all'),
                ])->values()->all(),
            );
        }

        if ($this->option('fail-on-not-ready') && ! $report['ready_for_cutover']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
