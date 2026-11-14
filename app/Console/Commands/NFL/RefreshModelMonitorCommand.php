<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflMonitorReportCache;
use Illuminate\Console\Command;

class RefreshModelMonitorCommand extends Command
{
    protected $signature = 'nfl:refresh-model-monitor {--limit=3 : Maximum report scopes per run, including the default} {--batch : Build at most one requested dashboard or shared report} {--force : Rebuild even when saved reports are fresh}';

    protected $description = 'Build last-good NFL admin reports outside HTTP requests';

    public function handle(NflMonitorReportCache $reports): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 4) {
            $this->error('Limit must be between one and four.');

            return self::FAILURE;
        }
        $force = (bool) $this->option('force');
        if (! $force && ! $reports->hasRecentDemand()) {
            $this->line('NFL admin monitor refresh skipped because report demand expired.');

            return self::SUCCESS;
        }

        $result = ! $force && $this->option('batch')
            ? $reports->refreshRequestedBatch()
            : $reports->refresh($limit, $force);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return empty($result['failures']) ? self::SUCCESS : self::FAILURE;
    }
}
