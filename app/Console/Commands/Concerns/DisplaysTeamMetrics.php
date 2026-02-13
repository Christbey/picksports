<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Collection;

trait DisplaysTeamMetrics
{
    /**
     * Display top teams table.
     */
    protected function displayTopTeamsByRating(
        int $season,
        string $modelClass,
        string $orderByColumn,
        int $limit = 10,
        array $columns = []
    ): void {
        $topTeams = $modelClass::query()
            ->where('season', $season)
            ->with('team')
            ->orderBy($orderByColumn, 'desc')
            ->limit($limit)
            ->get();

        if ($topTeams->isEmpty()) {
            $this->warn('No metrics found for this season.');

            return;
        }

        $this->table(
            $columns['headers'],
            $topTeams->map(fn ($metric, $index) => $this->formatMetricRow($metric, $index + 1, $columns['fields'])
            )
        );
    }

    /**
     * Display progress bar for bulk operations.
     */
    protected function runWithProgressBar(
        Collection $items,
        callable $callback
    ): int {
        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        $processed = 0;
        foreach ($items as $item) {
            if ($callback($item)) {
                $processed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $processed;
    }

    /**
     * Format metric row for table display.
     */
    protected function formatMetricRow(mixed $metric, int $rank, array $fields): array
    {
        $row = [$rank, $this->getTeamDisplayName($metric->team)];

        foreach ($fields as $field => $decimals) {
            $value = $metric->$field;
            $row[] = $value !== null ? round($value, $decimals) : 'N/A';
        }

        return $row;
    }

    /**
     * Get team display name.
     */
    protected function getTeamDisplayName(mixed $team): string
    {
        // Handle different team name structures
        if (isset($team->city) && isset($team->name)) {
            return "{$team->city} {$team->name}";
        }

        if (isset($team->school) && isset($team->mascot)) {
            return "{$team->school} {$team->mascot}";
        }

        if (isset($team->location) && isset($team->name)) {
            return "{$team->location} {$team->name}";
        }

        if (isset($team->abbreviation)) {
            return $team->abbreviation;
        }

        return $team->name ?? $team->school ?? 'Unknown Team';
    }
}
