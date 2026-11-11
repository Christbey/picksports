<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\CBB\Predictions\CbbCalculationReleaseDefinition;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'cbb:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=CBB canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical CBB pregame calculation release';

    protected function registrarClass(): string
    {
        return CbbCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'CBB';
    }

    protected function releaseVersion(): string
    {
        return (string) ($this->option('release-version') ?: CbbCalculationReleaseDefinition::SEMANTIC_VERSION);
    }
}
