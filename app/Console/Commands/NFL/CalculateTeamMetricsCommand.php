<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'nfl:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate NFL team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['city', 'name'];

    private ?string $activeSeasonType = null;

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : Calculate metrics for a specific season (defaults to current year)}\n {--team= : Calculate metrics for a specific team ID}\n {--season-type= : Limit NFL team metrics to a specific season type (e.g. 2 = regular, 3 = postseason)}",
            $this->commandName()
        );
    }

    public function handle(): int
    {
        $calculateMetrics = app($this->calculateMetricsActionClass());
        $season = (int) ($this->option('season') ?? date('Y'));
        $seasonTypes = $this->seasonTypesForRun();

        if ($seasonTypes === []) {
            $this->info("Calculating metrics for all teams ({$season})...");
            $this->warn('No NFL analytics season types are configured. Set nfl.season.analytics_types or pass --season-type.');

            return self::SUCCESS;
        }

        $singleTeamResult = $this->handleSingleTeamMetricsCalculation(
            $season,
            function (Model $team, int|string $seasonValue) use ($calculateMetrics, $seasonTypes) {
                $metrics = collect();

                foreach ($seasonTypes as $seasonType) {
                    $metric = $calculateMetrics->execute($team, (int) $seasonValue, $seasonType);
                    if ($metric) {
                        $metrics->push($metric);
                    }
                }

                if ($metrics->isEmpty()) {
                    return null;
                }

                foreach ($metrics as $metric) {
                    $this->line('Season Type: '.(string) $metric->season_type);
                    $this->displayTeamMetric($metric);
                }

                return $metrics->first();
            },
            fn (Model $team) => $this->teamDisplayName($team)
        );
        if ($singleTeamResult !== null) {
            return $singleTeamResult;
        }

        $this->info("Calculating metrics for all teams ({$season})...");

        $teamModelClass = $this->teamModelClass();
        $teams = $this->modifyTeamsQuery($teamModelClass::query(), $season)->get();
        $calculated = 0;
        $attempted = 0;
        $bar = $this->output->createProgressBar($teams->count() * count($seasonTypes));
        $bar->start();

        foreach ($teams as $team) {
            foreach ($seasonTypes as $seasonType) {
                $attempted++;
                if ($calculateMetrics->execute($team, $season, $seasonType)) {
                    $calculated++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Recalculated metrics for {$calculated} of {$attempted} team/season-type combinations.");

        $this->activeSeasonType = $this->displaySeasonType($seasonTypes);

        $this->newLine();
        $this->info($this->topTeamsTitle());

        $this->displayTopTeamsByRating(
            $season,
            $this->teamMetricModelClass(),
            $this->topRatingColumn(),
            10,
            [
                'headers' => $this->topTableHeaders(),
                'fields' => $this->topTableFields(),
            ]
        );

        return self::SUCCESS;
    }

    protected function modifyTopTeamsQuery(mixed $query, int|string $season): mixed
    {
        if ($this->activeSeasonType !== null) {
            $query->where('season_type', $this->activeSeasonType);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function seasonTypesForRun(): array
    {
        $requestedSeasonType = $this->option('season-type');

        if (is_string($requestedSeasonType) && trim($requestedSeasonType) !== '') {
            return [trim($requestedSeasonType)];
        }

        $analyticsTypes = config('nfl.season.analytics_types', []);
        if (! is_array($analyticsTypes)) {
            return [];
        }

        return collect($analyticsTypes)
            ->filter(fn ($type) => $type !== null && $type !== '')
            ->map(fn ($type) => (string) $type)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $seasonTypes
     */
    private function displaySeasonType(array $seasonTypes): ?string
    {
        $requestedSeasonType = $this->option('season-type');
        if (is_string($requestedSeasonType) && trim($requestedSeasonType) !== '') {
            return trim($requestedSeasonType);
        }

        $regularSeasonType = (string) config('nfl.season.types.regular', 2);

        if (in_array($regularSeasonType, $seasonTypes, true)) {
            return $regularSeasonType;
        }

        return $seasonTypes[0] ?? null;
    }
}
