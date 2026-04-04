<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\TeamRegressionRiskReportService;
use Illuminate\Console\Command;

class ReportRegressionRiskCommand extends Command
{
    protected $signature = 'nfl:report-regression-risk
        {--season=2026 : Season to forecast}
        {--mode=regression : Report mode: regression or breakout}
        {--as-of-date= : Snapshot date/time for preseason or in-season forecasts}
        {--require-historical-metrics : Only use captured team metric snapshots}
        {--limit=12 : Number of teams to include}
        {--output= : Optional JSON output path}';

    protected $description = 'Rank NFL teams by regression or breakout risk versus the previous season';

    public function handle(TeamRegressionRiskReportService $service): int
    {
        $season = (int) $this->option('season');
        $mode = (string) $this->option('mode');
        $asOfDate = $this->option('as-of-date');
        $requireHistoricalMetrics = (bool) $this->option('require-historical-metrics');
        $limit = max(1, (int) $this->option('limit'));
        $output = $this->option('output');

        $report = $service->generate(
            season: $season,
            asOfDate: $asOfDate ? (string) $asOfDate : null,
            requireHistoricalMetrics: $requireHistoricalMetrics,
            limit: $limit,
            mode: $mode,
        );

        $resolvedMode = (string) ($report['mode'] ?? 'regression');
        $label = $resolvedMode === 'breakout' ? 'breakout risk' : 'regression risk';
        $movementColumn = $resolvedMode === 'breakout' ? 'Rise' : 'Drop';

        $this->info("NFL {$label} for season {$season}");
        $this->table(
            ['Team', 'Prev Wins', 'Proj Wins', $movementColumn, 'Tier', 'Playoffs %', 'Div %', 'SB %'],
            array_map(fn (array $team): array => [
                (string) ($team['team_name'] ?? ''),
                (string) ($team['actual_wins'] ?? 0),
                number_format((float) ($team['projected_wins'] ?? 0.0), 3),
                number_format((float) ($resolvedMode === 'breakout' ? ($team['breakout_amount'] ?? 0.0) : ($team['regression_amount'] ?? 0.0)), 3),
                strtoupper(str_replace('_', ' ', (string) ($team['risk_tier'] ?? 'none'))),
                number_format((float) ($team['playoff_probability'] ?? 0.0) * 100, 1).'%',
                number_format((float) ($team['division_winner_probability'] ?? 0.0) * 100, 1).'%',
                number_format((float) ($team['super_bowl_champion_probability'] ?? 0.0) * 100, 1).'%',
            ], $report['teams'] ?? [])
        );

        if ($output) {
            @mkdir(dirname((string) $output), 0777, true);
            file_put_contents((string) $output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote report to {$output}");
        }

        return self::SUCCESS;
    }
}
