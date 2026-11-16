<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\TeamPlayoffForecastService;
use Illuminate\Console\Command;

class GenerateTeamPlayoffForecastCommand extends Command
{
    protected $signature = 'nfl:generate-team-playoff-forecast
        {--season=2025 : Season to forecast}
        {--as-of-date= : Snapshot date/time for preseason or in-season forecasts}
        {--require-historical-metrics : Only use captured team metric snapshots}
        {--simulations=5000 : Number of Monte Carlo simulations}
        {--seed=20260402 : Random seed for repeatable simulations}
        {--output= : Optional JSON output path}';

    protected $description = 'Generate NFL division, playoff, conference, and Super Bowl team forecast probabilities';

    public function handle(TeamPlayoffForecastService $forecastService): int
    {
        $season = (int) $this->option('season');
        $asOfDate = $this->option('as-of-date');
        $requireHistoricalMetrics = (bool) $this->option('require-historical-metrics');
        $simulations = max(100, (int) $this->option('simulations'));
        $seed = (int) $this->option('seed');
        $output = $this->option('output');

        $report = $forecastService->forecast(
            season: $season,
            asOfDate: $asOfDate ? (string) $asOfDate : null,
            requireHistoricalMetrics: $requireHistoricalMetrics,
            simulations: $simulations,
            seed: $seed,
        );

        $this->info("NFL team playoff forecast for season {$season}");
        $summary = $report['summary'] ?? [];
        if (isset($summary['attempted_simulations'])) {
            $this->line(sprintf('Simulations accepted %d of %d', $summary['simulations'], $summary['attempted_simulations']));
            $this->line(sprintf('Known postseason games %d completed, %d scheduled',
                $summary['completed_postseason_games'], $summary['scheduled_postseason_games']));
        }

        $tieModel = $report['tie_model'] ?? null;
        if (isset($tieModel['tie_probability'])) {
            $this->line(sprintf('Historical tie rate %.2f%% (%d ties in %d games)',
                $tieModel['tie_probability'] * 100, $tieModel['sample_ties'], $tieModel['sample_games']));
        }

        foreach ($report['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Division leaders');
        $this->table(
            ['Division', 'Team', 'Proj Wins', 'Div %', 'Playoffs %'],
            array_map(fn (array $team): array => [
                "{$team['conference']} {$team['division']}",
                (string) $team['team_name'],
                number_format((float) $team['projected_wins'], 3),
                number_format((float) $team['division_winner_probability'] * 100, 1).'%',
                number_format((float) $team['make_playoffs_probability'] * 100, 1).'%',
            ], $report['division_leaders'] ?? [])
        );

        $this->newLine();
        $this->line('Conference leaders');
        $this->table(
            ['Conference', 'Team', 'Playoffs %', 'Conf Champ %', 'SB %'],
            array_map(fn (array $team): array => [
                (string) $team['conference'],
                (string) $team['team_name'],
                number_format((float) $team['make_playoffs_probability'] * 100, 1).'%',
                number_format((float) $team['conference_champion_probability'] * 100, 1).'%',
                number_format((float) $team['super_bowl_champion_probability'] * 100, 1).'%',
            ], $report['conference_leaders'] ?? [])
        );

        $this->newLine();
        $this->line('Top Super Bowl odds');
        $this->table(
            ['Team', 'Conference', 'Division', 'Proj Wins', 'Playoffs %', 'Conf Champ %', 'SB %'],
            array_map(fn (array $team): array => [
                (string) $team['team_name'],
                (string) $team['conference'],
                (string) $team['division'],
                number_format((float) $team['projected_wins'], 3),
                number_format((float) $team['make_playoffs_probability'] * 100, 1).'%',
                number_format((float) $team['conference_champion_probability'] * 100, 1).'%',
                number_format((float) $team['super_bowl_champion_probability'] * 100, 1).'%',
            ], $report['super_bowl_leaders'] ?? [])
        );

        if ($output) {
            @mkdir(dirname((string) $output), 0777, true);
            file_put_contents((string) $output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote report to {$output}");
        }

        return self::SUCCESS;
    }
}
