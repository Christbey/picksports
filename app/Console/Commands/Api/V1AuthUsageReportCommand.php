<?php

namespace App\Console\Commands\Api;

class V1AuthUsageReportCommand extends V1UsageReportCommand
{
    protected $signature = 'api:v1-auth-usage-report
        {--path=* : Log file path(s) to inspect. Defaults to storage/logs/laravel*.log}
        {--limit=25 : Maximum number of grouped routes to display}
        {--json : Output machine-readable JSON}';

    protected $description = 'Summarize logged legacy auth API v1 usage before auth route retirement.';

    protected string $eventName = 'api.v1.auth.usage';

    protected string $emptyMessage = 'No api.v1.auth.usage entries found.';
}
