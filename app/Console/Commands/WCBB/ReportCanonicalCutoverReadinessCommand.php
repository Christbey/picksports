<?php

namespace App\Console\Commands\WCBB;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\WCBB\Predictions\WcbbCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'wcbb:report-canonical-cutover-readiness
        {--season= : Limit the report to a WCBB season}
        {--json : Emit machine-readable JSON}
        {--fail-on-not-ready : Return a failure code when cutover requirements are incomplete}';

    protected $description = 'Report whether WCBB canonical predictions and evaluations are ready for API cutover';

    protected function readinessClass(): string
    {
        return WcbbCanonicalCutoverReadinessService::class;
    }
}
