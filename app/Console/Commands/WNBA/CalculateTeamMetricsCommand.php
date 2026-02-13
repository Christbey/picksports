<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\CalculateTeamMetrics;
use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use Illuminate\Console\Command;

class CalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;

    protected $signature = 'wnba:calculate-team-metrics
                            {--season= : Calculate metrics for a specific season (defaults to current year)}
                            {--team= : Calculate metrics for a specific team ID}';

    protected $description = 'Calculate WNBA team advanced metrics (offensive/defensive efficiency, tempo, SOS)';

    public function handle(): int
    {
        $calculateMetrics = new CalculateTeamMetrics;

        $season = $this->option('season') ?? date('Y');

        if ($teamId = $this->option('team')) {
            $team = Team::find($teamId);

            if (! $team) {
                $this->error("Team with ID {$teamId} not found.");

                return Command::FAILURE;
            }

            $this->info("Calculating metrics for {$team->display_name} ({$season})...");

            $metric = $calculateMetrics->execute($team, $season);

            if (! $metric) {
                $this->warn('No completed games found for this team in this season.');

                return Command::SUCCESS;
            }

            $this->displayTeamMetric($metric);

            return Command::SUCCESS;
        }

        // Calculate for all teams
        $this->info("Calculating metrics for all teams ({$season})...");

        $teams = Team::all();
        $calculated = $this->runWithProgressBar(
            $teams,
            fn ($team) => $calculateMetrics->execute($team, $season)
        );

        $this->info("Calculated metrics for {$calculated} teams.");

        // Show top teams by net rating
        $this->newLine();
        $this->info('Top 10 Teams by Net Rating:');

        $this->displayTopTeamsByRating(
            $season,
            TeamMetric::class,
            'net_rating',
            10,
            [
                'headers' => ['Rank', 'Team', 'Off Eff', 'Def Eff', 'Net Rtg', 'Tempo', 'SOS'],
                'fields' => [
                    'offensive_efficiency' => 1,
                    'defensive_efficiency' => 1,
                    'net_rating' => 1,
                    'tempo' => 1,
                    'strength_of_schedule' => 3,
                ],
            ]
        );

        return Command::SUCCESS;
    }

    protected function displayTeamMetric(TeamMetric $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Efficiency', round($metric->offensive_efficiency, 1)],
                ['Defensive Efficiency', round($metric->defensive_efficiency, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Tempo', round($metric->tempo, 1)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
