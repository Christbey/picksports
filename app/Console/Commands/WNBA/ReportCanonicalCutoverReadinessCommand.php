<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\WNBA\Predictions\WnbaCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'wnba:report-canonical-cutover-readiness {--season=} {--offseason} {--json} {--fail-on-not-ready}';

    protected $description = 'Report WNBA canonical cutover readiness';

    protected function readinessClass(): string
    {
        return WnbaCanonicalCutoverReadinessService::class;
    }
}
