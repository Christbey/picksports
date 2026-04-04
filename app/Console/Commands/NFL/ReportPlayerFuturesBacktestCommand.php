<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\PlayerFuturesBacktestService;
use Illuminate\Console\Command;

class ReportPlayerFuturesBacktestCommand extends Command
{
    protected $signature = 'nfl:report-player-futures-backtest
        {--season=2025 : Season to evaluate}
        {--market= : Optional futures market key}
        {--from-week=1 : First cutoff week to evaluate}
        {--to-week= : Last cutoff week to evaluate}
        {--min-sample=5 : Minimum sample per market section}
        {--output= : Optional JSON output path}';

    protected $description = 'Backtest NFL player futures projections from weekly season cutoffs';

    public function handle(PlayerFuturesBacktestService $backtestService): int
    {
        $season = (int) $this->option('season');
        $market = $this->option('market');
        $fromWeek = max(1, (int) $this->option('from-week'));
        $toWeekOption = $this->option('to-week');
        $toWeek = ($toWeekOption === null || $toWeekOption === '') ? null : (int) $toWeekOption;
        $minSample = max(1, (int) $this->option('min-sample'));
        $output = $this->option('output');

        $report = $backtestService->evaluate(
            season: $season,
            market: $market ? (string) $market : null,
            fromWeek: $fromWeek,
            toWeek: $toWeek,
            minSample: $minSample,
        );

        $summary = $report['summary'];

        $this->info("NFL player futures backtest for season {$season}");
        $this->table(
            ['Rows', 'MAE', 'RMSE', 'Bias', 'Line Rows', 'Over Acc', 'Over Brier'],
            [[
                (string) ($summary['count'] ?? 0),
                $this->fmt($summary['mae'] ?? null),
                $this->fmt($summary['rmse'] ?? null),
                $this->fmt($summary['bias'] ?? null),
                (string) ($summary['line_count'] ?? 0),
                $this->fmt($summary['over_accuracy'] ?? null),
                $this->fmt($summary['over_brier'] ?? null),
            ]]
        );

        $weekRows = array_map(function (array $week): array {
            $summary = $week['summary'] ?? [];

            return [
                (string) ($week['week'] ?? ''),
                (string) ($summary['count'] ?? 0),
                $this->fmt($summary['mae'] ?? null),
                $this->fmt($summary['rmse'] ?? null),
                $this->fmt($summary['over_accuracy'] ?? null),
                $this->fmt($summary['over_brier'] ?? null),
            ];
        }, $report['weeks'] ?? []);

        if ($weekRows !== []) {
            $this->newLine();
            $this->line('By cutoff week');
            $this->table(['Week', 'Rows', 'MAE', 'RMSE', 'Over Acc', 'Over Brier'], $weekRows);
        }

        if ($output) {
            @mkdir(dirname((string) $output), 0777, true);
            file_put_contents((string) $output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote report to {$output}");
        }

        return self::SUCCESS;
    }

    protected function fmt(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return 'n/a';
        }

        return number_format((float) $value, 4);
    }
}
