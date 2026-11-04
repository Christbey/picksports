<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\CBB\Predictions\CbbCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'cbb:report-canonical-cutover-readiness
        {--season= : Limit the report to a CBB season}
        {--json : Emit machine-readable JSON}
        {--fail-on-not-ready : Return a failure code when cutover requirements are incomplete}';

    protected $description = 'Report whether CBB canonical predictions and evaluations are ready for API cutover';

    protected function readinessClass(): string
    {
        return CbbCanonicalCutoverReadinessService::class;
    }
}
