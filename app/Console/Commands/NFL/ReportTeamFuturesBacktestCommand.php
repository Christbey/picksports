<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\TeamFuturesBacktestService;
use Illuminate\Console\Command;

class ReportTeamFuturesBacktestCommand extends Command
{
    protected $signature = 'nfl:report-team-futures-backtest
        {--season=2025 : Season to evaluate}
        {--market=season_wins : Team futures market to evaluate}
        {--from-date= : First snapshot date/time to include}
        {--to-date= : Last snapshot date/time to include}
        {--min-sample=5 : Minimum sample for market section}
        {--require-historical-metrics : Only evaluate dates with captured team metric snapshots}
        {--output= : Optional JSON output path}';

    protected $description = 'Backtest NFL team futures projections against historical futures snapshots';

    public function handle(TeamFuturesBacktestService $backtestService): int
    {
        $season = (int) $this->option('season');
        $market = (string) $this->option('market');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        $minSample = max(1, (int) $this->option('min-sample'));
        $requireHistoricalMetrics = (bool) $this->option('require-historical-metrics');
        $output = $this->option('output');

        $report = $backtestService->evaluate(
            season: $season,
            market: $market,
            fromDate: $fromDate ? (string) $fromDate : null,
            toDate: $toDate ? (string) $toDate : null,
            minSample: $minSample,
            requireHistoricalMetrics: $requireHistoricalMetrics,
        );

        $summary = $report['summary'] ?? [];

        $this->info("NFL team futures backtest for season {$season} ({$market})");
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

        $dateRows = array_map(function (array $date): array {
            $summary = $date['summary'] ?? [];

            return [
                (string) ($date['date'] ?? ''),
                (string) ($summary['count'] ?? 0),
                $this->fmt($summary['mae'] ?? null),
                $this->fmt($summary['rmse'] ?? null),
                $this->fmt($summary['over_accuracy'] ?? null),
                $this->fmt($summary['over_brier'] ?? null),
            ];
        }, $report['dates'] ?? []);

        if ($dateRows !== []) {
            $this->newLine();
            $this->line('By snapshot date');
            $this->table(['Date', 'Rows', 'MAE', 'RMSE', 'Over Acc', 'Over Brier'], $dateRows);
        }

        if ($requireHistoricalMetrics && (int) ($summary['count'] ?? 0) === 0) {
            $this->newLine();
            $this->warn('No evaluable rows met the historical-metrics requirement for the selected date range.');
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
