<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\TeamFuturesBettingReportService;
use Illuminate\Console\Command;

class ReportTeamFuturesBetsCommand extends Command
{
    protected $signature = 'nfl:report-team-futures-bets
        {--season=2025 : Season to evaluate}
        {--market=season_wins : Team futures market to evaluate}
        {--as-of-date= : Snapshot date/time to evaluate}
        {--min-edge=0.02 : Minimum model edge required}
        {--limit=10 : Max number of bets to show}
        {--require-historical-metrics : Only use captured team metric snapshots}
        {--output= : Optional JSON output path}';

    protected $description = 'Rank the best NFL team futures bets from the model projections';

    public function handle(TeamFuturesBettingReportService $reportService): int
    {
        $season = (int) $this->option('season');
        $market = (string) $this->option('market');
        $asOfDate = $this->option('as-of-date');
        $minEdge = max(0.0, (float) $this->option('min-edge'));
        $limit = max(1, (int) $this->option('limit'));
        $requireHistoricalMetrics = (bool) $this->option('require-historical-metrics');
        $output = $this->option('output');

        $report = $reportService->generate(
            season: $season,
            market: $market,
            asOfDate: $asOfDate ? (string) $asOfDate : null,
            requireHistoricalMetrics: $requireHistoricalMetrics,
            minEdge: $minEdge,
            limit: $limit,
        );

        $summary = $report['summary'] ?? [];
        $calibration = $report['calibration'] ?? [];
        $this->info("NFL team futures bets for season {$season} ({$market})");
        $this->table(
            ['Bets', 'Avg Edge', 'Avg EV', 'Avg Kelly'],
            [[
                (string) ($summary['count'] ?? 0),
                $this->fmt($summary['average_edge'] ?? null),
                $this->fmt($summary['average_expected_value'] ?? null),
                $this->fmt($summary['average_kelly_fraction'] ?? null),
            ]]
        );

        if (is_array($calibration)) {
            $this->line(sprintf(
                'Calibration: %s | shrink=%s | sample=%s',
                (string) ($calibration['method'] ?? 'n/a'),
                $this->fmt($calibration['shrink_factor'] ?? null),
                (string) ($calibration['sample_size'] ?? 0),
            ));
        }

        $bets = array_map(function (array $bet): array {
            return [
                (string) ($bet['team_name'] ?? ''),
                strtoupper((string) ($bet['side'] ?? '')),
                $this->fmt($bet['line'] ?? null),
                (string) ($bet['price'] ?? ''),
                $this->fmt($bet['projected_total'] ?? null),
                $this->fmt($bet['raw_model_probability'] ?? null),
                $this->fmt($bet['model_probability'] ?? null),
                $this->fmt($bet['edge'] ?? null),
                $this->fmt($bet['expected_value'] ?? null),
                (string) ($bet['fair_price'] ?? ''),
                $this->fmt($bet['kelly_fraction'] ?? null),
            ];
        }, $report['bets'] ?? []);

        if ($bets !== []) {
            $this->newLine();
            $this->line('Top bets');
            $this->table(
                ['Team', 'Side', 'Line', 'Price', 'Proj', 'Raw P', 'Cal P', 'Edge', 'EV', 'Fair', 'Kelly'],
                $bets
            );
        } else {
            $this->newLine();
            $this->warn('No bets met the requested edge threshold.');
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
