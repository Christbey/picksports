<?php

namespace App\Console\Commands\WCBB;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'wcbb:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=WCBB canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical WCBB pregame calculation release';

    protected function registrarClass(): string
    {
        return WcbbCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'WCBB';
    }

    protected function releaseVersion(): string
    {
        return (string) ($this->option('release-version') ?: WcbbCalculationReleaseDefinition::SEMANTIC_VERSION);
    }
}
