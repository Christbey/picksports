<?php

namespace App\Console\Commands\Sports\Canonical;

use Illuminate\Console\Command;

abstract class AbstractReportCanonicalCutoverReadinessCommand extends Command
{
    public function handle(): int
    {
        $readiness = app($this->readinessClass());
        $season = filled($this->option('season')) ? (int) $this->option('season') : null;
        $report = $this->getDefinition()->hasOption('week')
            ? $readiness->report($season, filled($this->option('week')) ? (int) $this->option('week') : null)
            : $readiness->report($season);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Value'], collect($report)->map(fn (mixed $value, string $key): array => [
                $key, is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'all'),
            ])->values()->all());
        }

        return $this->option('fail-on-not-ready') && ! $report['ready_for_cutover'] ? self::FAILURE : self::SUCCESS;
    }

    /** @return class-string */
    abstract protected function readinessClass(): string;
}
