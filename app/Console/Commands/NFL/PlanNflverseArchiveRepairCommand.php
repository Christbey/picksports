<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflverseArchiveRepairPlanner;
use Illuminate\Console\Command;

class PlanNflverseArchiveRepairCommand extends Command
{
    protected $signature = 'nfl:plan-nflverse-archive-repair {--from-season=} {--to-season=} {--detailed : Include original evidence and proposed spread payloads}';

    protected $description = 'Read-only NFL archive correction manifest; no apply mode or historical regrading';

    public function handle(NflverseArchiveRepairPlanner $planner): int
    {
        $from = filter_var($this->option('from-season'), FILTER_VALIDATE_INT);
        $to = filter_var($this->option('to-season'), FILTER_VALIDATE_INT);
        if ($from === false || $to === false || $from < 2000 || $to > 2100 || $from > $to) {
            $this->error('Explicit --from-season and --to-season are required (2000–2100, ordered).');

            return self::INVALID;
        }
        $report = $planner->run($from, $to, (bool) $this->option('detailed'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return collect(array_keys($report['statuses']))->contains(fn ($status) => str_starts_with($status, 'blocked_')) ? self::FAILURE : self::SUCCESS;
    }
}
