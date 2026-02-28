<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use App\Console\Commands\Sports\Concerns\HandlesSingleTeamMetricsCalculation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractAdjustedBasketballTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;
    use ResolvesRequiredConfig;
    use HandlesSingleTeamMetricsCalculation;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const CALCULATE_METRICS_ACTION_CLASS = '';

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_METRIC_MODEL_CLASS = Model::class;

    protected const METRICS_CONFIG_PREFIX = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $calculateMetrics = app($this->calculateMetricsActionClass());
        $season = $this->option('season') ?? date('Y');

        $singleTeamResult = $this->handleSingleTeamMetricsCalculation(
            $season,
            fn (Model $team, int|string $seasonValue) => $calculateMetrics->execute($team, $seasonValue),
            fn (Model $team) => (string) ($team->abbreviation ?? $team->id)
        );
        if ($singleTeamResult !== null) {
            return $singleTeamResult;
        }

        $this->info("Calculating metrics for all teams ({$season})...");

        $teamModelClass = $this->teamModelClass();
        $teamsCount = $teamModelClass::count();

        if ($teamsCount === 0) {
            $this->warn('No teams found in database.');

            return self::SUCCESS;
        }

        $this->newLine();

        $calculated = $calculateMetrics->executeForAllTeams($season);

        $this->newLine();
        $this->info("Calculated metrics for {$calculated} teams.");

        $this->newLine();
        $this->info('Top 10 Teams by Adjusted Net Rating:');

        $this->displayTopTeamsByRating(
            $season,
            $this->teamMetricModelClass(),
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

        return self::SUCCESS;
    }

    protected function displayTeamMetric(Model $metric): void
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
        $this->info('Rolling Metrics (Last '.config($this->metricsConfigPrefix().'.metrics.rolling_window_size').' Games):');
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

    /**
     * @return class-string
     */
    protected function calculateMetricsActionClass(): string
    {
        return $this->requiredString(static::CALCULATE_METRICS_ACTION_CLASS, 'CALCULATE_METRICS_ACTION_CLASS must be defined.');
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        return $this->requiredNonDefaultString(static::TEAM_MODEL_CLASS, Model::class, 'TEAM_MODEL_CLASS must be defined.');
    }

    /**
     * @return class-string<Model>
     */
    protected function teamMetricModelClass(): string
    {
        return $this->requiredNonDefaultString(static::TEAM_METRIC_MODEL_CLASS, Model::class, 'TEAM_METRIC_MODEL_CLASS must be defined.');
    }

    protected function metricsConfigPrefix(): string
    {
        return $this->requiredString(static::METRICS_CONFIG_PREFIX, 'METRICS_CONFIG_PREFIX must be defined.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : Calculate metrics for a specific season (defaults to current year)}\n {--team= : Calculate metrics for a specific team ID}",
            $this->commandName()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }
}
