<?php

namespace App\Console\Commands\Sports\Concerns;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

trait HandlesSingleTeamMetricsCalculation
{
    /**
     * @param  callable(Model, int|string): mixed  $executeForTeam
     * @param  callable(Model): string  $teamLabel
     */
    protected function handleSingleTeamMetricsCalculation(
        int|string $season,
        callable $executeForTeam,
        callable $teamLabel
    ): ?int {
        $teamId = $this->option('team');
        if (! $teamId) {
            return null;
        }

        $teamModelClass = $this->teamModelClass();
        $team = $teamModelClass::find($teamId);

        if (! $team) {
            $this->error("Team with ID {$teamId} not found.");

            return Command::FAILURE;
        }

        $this->info("Calculating metrics for {$teamLabel($team)} ({$season})...");

        $metric = $executeForTeam($team, $season);

        if (! $metric) {
            $this->warn('No completed games found for this team in this season.');

            return Command::SUCCESS;
        }

        $this->displayTeamMetric($metric);

        return Command::SUCCESS;
    }
}
