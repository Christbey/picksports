<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'nfl:report-canonical-cutover-readiness
        {--season=}
        {--date= : Start the readiness horizon on this business date (YYYY-MM-DD)}
        {--days-forward=8 : Require complete coverage for this many days}
        {--json}
        {--fail-on-not-ready}';

    protected $description = 'Report NFL canonical cutover readiness';

    protected function readinessClass(): string
    {
        return NflCanonicalCutoverReadinessService::class;
    }
}
