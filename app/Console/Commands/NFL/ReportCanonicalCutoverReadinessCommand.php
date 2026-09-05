<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'nfl:report-canonical-cutover-readiness {--season=} {--json} {--fail-on-not-ready}';

    protected $description = 'Report NFL canonical cutover readiness';

    protected function readinessClass(): string
    {
        return NflCanonicalCutoverReadinessService::class;
    }
}
