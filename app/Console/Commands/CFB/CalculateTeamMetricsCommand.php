<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateTeamMetrics;
use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use Illuminate\Console\Command;

class CalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;

    protected $signature = 'cfb:calculate-team-metrics
                            {--season= : Calculate metrics for a specific season (defaults to current year)}
                            {--team= : Calculate metrics for a specific team ID}';

    protected $description = 'Calculate CFB team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

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
                'headers' => ['Rank', 'Team', 'Off Rtg', 'Def Rtg', 'Net Rtg', 'Yards/G', 'TO Diff', 'SOS'],
                'fields' => [
                    'offensive_rating' => 1,
                    'defensive_rating' => 1,
                    'net_rating' => 1,
                    'yards_per_game' => 1,
                    'turnover_differential' => 1,
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
                ['Offensive Rating', round($metric->offensive_rating, 1)],
                ['Defensive Rating', round($metric->defensive_rating, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Points Per Game', round($metric->points_per_game, 1)],
                ['Points Allowed Per Game', round($metric->points_allowed_per_game, 1)],
                ['Yards Per Game', round($metric->yards_per_game, 1)],
                ['Yards Allowed Per Game', round($metric->yards_allowed_per_game, 1)],
                ['Passing Yards Per Game', round($metric->passing_yards_per_game, 1)],
                ['Rushing Yards Per Game', round($metric->rushing_yards_per_game, 1)],
                ['Turnover Differential', round($metric->turnover_differential, 1)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
