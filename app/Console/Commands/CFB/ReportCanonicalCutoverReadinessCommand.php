<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\CFB\Predictions\CfbCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'cfb:report-canonical-cutover-readiness {--season=} {--week=} {--json} {--fail-on-not-ready}';

    protected $description = 'Report CFB canonical cutover readiness';

    protected function readinessClass(): string
    {
        return CfbCanonicalCutoverReadinessService::class;
    }
}
