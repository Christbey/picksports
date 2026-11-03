<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\Canonical\AbstractReportCanonicalCutoverReadinessCommand;
use App\Services\MLB\Predictions\MlbCanonicalCutoverReadinessService;

class ReportCanonicalCutoverReadinessCommand extends AbstractReportCanonicalCutoverReadinessCommand
{
    protected $signature = 'mlb:report-canonical-cutover-readiness {--season=} {--offseason} {--json} {--fail-on-not-ready}';

    protected $description = 'Report MLB canonical cutover readiness';

    protected function readinessClass(): string
    {
        return MlbCanonicalCutoverReadinessService::class;
    }
}
