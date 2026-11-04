<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\NBA\Predictions\NbaCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'nba:report-canonical-cutover-readiness
        {--season= : Limit the report to an NBA season}
        {--json : Emit machine-readable JSON}
        {--fail-on-not-ready : Return a failure code when cutover requirements are incomplete}';

    protected $description = 'Report whether NBA canonical predictions and evaluations are ready for API cutover';

    protected function readinessClass(): string
    {
        return NbaCanonicalCutoverReadinessService::class;
    }
}
