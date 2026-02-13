<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\CalculateTeamMetrics;
use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use Illuminate\Console\Command;

class CalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;

    protected $signature = 'wcbb:calculate-team-metrics
                            {--season= : Calculate metrics for a specific season (defaults to current year)}
                            {--team= : Calculate metrics for a specific team ID}';

    protected $description = 'Calculate WCBB team advanced metrics (offensive/defensive efficiency, tempo, net rating)';

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

            $this->info("Calculating metrics for {$team->abbreviation} ({$season})...");

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

        $teamsCount = Team::count();

        if ($teamsCount == 0) {
            $this->warn('No teams found in database.');

            return Command::SUCCESS;
        }

        $this->newLine();

        // Use executeForAllTeams which includes opponent adjustments
        $calculated = $calculateMetrics->executeForAllTeams($season);

        $this->newLine();
        $this->info("Calculated metrics for {$calculated} teams.");

        // Show top teams by adjusted net rating
        $this->newLine();
        $this->info('Top 10 Teams by Adjusted Net Rating:');

        $this->displayTopTeamsByRating(
            $season,
            TeamMetric::class,
            'adj_net_rating',
            10,
            [
                'headers' => ['Rank', 'Team', 'AdjO', 'AdjD', 'AdjNet', 'AdjT', 'Games', 'Iters'],
                'fields' => [
                    'adj_offensive_efficiency' => 1,
                    'adj_defensive_efficiency' => 1,
                    'adj_net_rating' => 1,
                    'adj_tempo' => 1,
                    'games_played' => 0,
                    'iteration_count' => 0,
                ],
            ]
        );

        return Command::SUCCESS;
    }

    protected function displayTeamMetric(TeamMetric $metric): void
    {
        $this->newLine();
        $this->info('Raw Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Efficiency', round($metric->offensive_efficiency, 1)],
                ['Defensive Efficiency', round($metric->defensive_efficiency, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Tempo', round($metric->tempo, 1)],
                ['Strength of Schedule', $metric->strength_of_schedule ? round($metric->strength_of_schedule, 3) : 'N/A'],
                ['Games Played', $metric->games_played],
            ]
        );

        if ($metric->adj_offensive_efficiency !== null) {
            $this->newLine();
            $this->info('Adjusted Metrics (Opponent-Adjusted):');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Adjusted Offensive Efficiency', round($metric->adj_offensive_efficiency, 1)],
                    ['Adjusted Defensive Efficiency', round($metric->adj_defensive_efficiency, 1)],
                    ['Adjusted Net Rating', round($metric->adj_net_rating, 1)],
                    ['Adjusted Tempo', round($metric->adj_tempo, 1)],
                    ['Iteration Count', $metric->iteration_count ?? 'N/A'],
                ]
            );
        }

        $this->newLine();
        $this->info('Rolling Metrics (Last '.config('wcbb.metrics.rolling_window_size').' Games):');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Rolling Offensive Efficiency', $metric->rolling_offensive_efficiency ? round($metric->rolling_offensive_efficiency, 1) : 'N/A'],
                ['Rolling Defensive Efficiency', $metric->rolling_defensive_efficiency ? round($metric->rolling_defensive_efficiency, 1) : 'N/A'],
                ['Rolling Net Rating', $metric->rolling_net_rating ? round($metric->rolling_net_rating, 1) : 'N/A'],
                ['Rolling Tempo', $metric->rolling_tempo ? round($metric->rolling_tempo, 1) : 'N/A'],
                ['Rolling Games Count', $metric->rolling_games_count],
            ]
        );

        $this->newLine();
        $this->info('Home/Away Splits:');
        $this->table(
            ['Location', 'Games', 'Off Eff', 'Def Eff'],
            [
                [
                    'Home',
                    $metric->home_games,
                    $metric->home_offensive_efficiency ? round($metric->home_offensive_efficiency, 1) : 'N/A',
                    $metric->home_defensive_efficiency ? round($metric->home_defensive_efficiency, 1) : 'N/A',
                ],
                [
                    'Away',
                    $metric->away_games,
                    $metric->away_offensive_efficiency ? round($metric->away_offensive_efficiency, 1) : 'N/A',
                    $metric->away_defensive_efficiency ? round($metric->away_defensive_efficiency, 1) : 'N/A',
                ],
            ]
        );

        $this->newLine();
        $this->info('Calculation Info:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Possession Coefficient', $metric->possession_coefficient],
                ['Meets Minimum', $metric->meets_minimum ? 'Yes' : 'No'],
                ['Calculation Date', $metric->calculation_date],
            ]
        );
    }
}
