<?php

namespace App\Console\Commands\Sports;

use App\Services\Predictions\Review\GameAnalysisCoverage;
use Illuminate\Console\Command;

class ReportGameAnalysisCommand extends Command
{
    protected $signature = 'sports:report-game-analysis {--sport=* : Scope to supported sports}';

    protected $description = 'Read shared analysis backlog, deadline misses, source health and measured or unknown usage';

    public function handle(GameAnalysisCoverage $coverage): int
    {
        try {
            $report = $coverage->report(array_map('strtolower', (array) $this->option('sport')));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
