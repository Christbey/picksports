<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateTeamMetrics;
use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Console\Command;

class CalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;

    protected $signature = 'mlb:calculate-team-metrics
                            {--season= : Calculate metrics for a specific season (defaults to current year)}
                            {--team= : Calculate metrics for a specific team ID}';

    protected $description = 'Calculate MLB team metrics (offensive, pitching, defensive ratings, ERA, batting average)';

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

            $this->info("Calculating metrics for {$team->location} {$team->name} ({$season})...");

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

        // Show top teams by offensive rating
        $this->newLine();
        $this->info('Top 10 Teams by Offensive Rating:');

        $this->displayTopTeamsByRating(
            $season,
            TeamMetric::class,
            'offensive_rating',
            10,
            [
                'headers' => ['Rank', 'Team', 'Off Rtg', 'Pitch Rtg', 'Def Rtg', 'R/G', 'RA/G', 'AVG', 'ERA', 'SOS'],
                'fields' => [
                    'offensive_rating' => 2,
                    'pitching_rating' => 2,
                    'defensive_rating' => 2,
                    'runs_per_game' => 2,
                    'runs_allowed_per_game' => 2,
                    'batting_average' => 3,
                    'team_era' => 2,
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
                ['Offensive Rating', round($metric->offensive_rating, 2)],
                ['Pitching Rating', round($metric->pitching_rating, 2)],
                ['Defensive Rating', round($metric->defensive_rating, 2)],
                ['Runs Per Game', round($metric->runs_per_game, 2)],
                ['Runs Allowed Per Game', round($metric->runs_allowed_per_game, 2)],
                ['Batting Average', round($metric->batting_average, 3)],
                ['Team ERA', round($metric->team_era, 2)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
