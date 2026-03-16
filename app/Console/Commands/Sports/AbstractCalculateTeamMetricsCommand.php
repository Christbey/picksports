<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\DisplaysTeamMetrics;
use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use App\Console\Commands\Sports\Concerns\HandlesSingleTeamMetricsCalculation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractCalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;
    use HandlesSingleTeamMetricsCalculation;
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const CALCULATE_METRICS_ACTION_CLASS = '';

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_METRIC_MODEL_CLASS = Model::class;

    /**
     * @var array<int, string>
     */
    protected const TEAM_DISPLAY_FIELDS = [];

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
            fn (Model $team) => $this->teamDisplayName($team)
        );
        if ($singleTeamResult !== null) {
            return $singleTeamResult;
        }

        $this->info("Calculating metrics for all teams ({$season})...");

        $this->beforeBulkCalculation($calculateMetrics, $season);

        $teamModelClass = $this->teamModelClass();
        $teams = $this->modifyTeamsQuery($teamModelClass::query(), $season)->get();
        $calculated = $this->runWithProgressBar(
            $teams,
            fn ($team) => $calculateMetrics->execute($team, $season)
        );

        $totalTeams = $teams->count();
        $skipped = max(0, $totalTeams - $calculated);

        $this->info("Recalculated metrics for {$calculated} of {$totalTeams} teams.");
        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} teams (typically no completed games matched the season/status/type filters).");
        }

        $this->newLine();
        if ($calculated === 0) {
            $existingCount = $this->teamMetricModelClass()::query()
                ->where('season', $season)
                ->count();

            if ($existingCount > 0) {
                $this->warn("No team metrics were recalculated for {$season}. Showing {$existingCount} existing metric rows currently stored in DB.");
            } else {
                $this->warn("No team metrics are available for {$season}.");
            }

            $this->displayNoRecalculationDiagnostics($season);
        }

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

    protected function teamDisplayName(Model $team): string
    {
        if (static::TEAM_DISPLAY_FIELDS === []) {
            throw new \RuntimeException('TEAM_DISPLAY_FIELDS must be defined.');
        }

        $parts = array_values(array_filter(
            array_map(fn (string $field) => isset($team->{$field}) ? (string) $team->{$field} : '', static::TEAM_DISPLAY_FIELDS),
            fn (string $value) => $value !== ''
        ));

        return implode(' ', $parts);
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

    abstract protected function topTeamsTitle(): string;

    abstract protected function topRatingColumn(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function topTableHeaders(): array;

    /**
     * @return array<string, int>
     */
    abstract protected function topTableFields(): array;

    /**
     * Hook for sport-specific diagnostics when no teams were recalculated.
     */
    protected function displayNoRecalculationDiagnostics(int|string $season): void {}

    protected function modifyTeamsQuery(Builder $query, int|string $season): Builder
    {
        return $query;
    }

    protected function beforeBulkCalculation(object $calculateMetrics, int|string $season): void {}
}
