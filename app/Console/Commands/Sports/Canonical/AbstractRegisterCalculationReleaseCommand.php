<?php

namespace App\Console\Commands\Sports\Canonical;

use App\Models\CalculationRelease;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

abstract class AbstractRegisterCalculationReleaseCommand extends Command
{
    public function handle(): int
    {
        $registrar = app($this->registrarClass());
        $release = $registrar->register(
            semanticVersion: $this->releaseVersion(),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: filled($this->option('effective-at')) ? CarbonImmutable::parse((string) $this->option('effective-at')) : null,
        );
        $this->displayRelease($release);

        return self::SUCCESS;
    }

    protected function releaseVersion(): string
    {
        return (string) $this->option('release-version');
    }

    protected function displayRelease(CalculationRelease $release): void
    {
        $this->info($this->sportLabel()." calculation release {$release->semantic_version} is {$release->status}.");
    }

    /** @return class-string */
    abstract protected function registrarClass(): string;

    abstract protected function sportLabel(): string;
}
