<?php

namespace App\Console\Commands\Sports\Canonical;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

abstract class AbstractRegisterCalculationReleaseCommand extends Command
{
    public function handle(): int
    {
        $registrar = app($this->registrarClass());
        $release = $registrar->register(
            semanticVersion: (string) $this->option('release-version'),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: filled($this->option('effective-at')) ? CarbonImmutable::parse((string) $this->option('effective-at')) : null,
        );
        $this->info($this->sportLabel()." calculation release {$release->semantic_version} is {$release->status}.");

        return self::SUCCESS;
    }

    /** @return class-string */
    abstract protected function registrarClass(): string;

    abstract protected function sportLabel(): string;
}
