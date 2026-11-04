<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\NBA\Predictions\NbaCalculationReleaseDefinition;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'nba:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=NBA canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical NBA pregame calculation release';

    protected function registrarClass(): string
    {
        return NbaCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'NBA';
    }

    protected function releaseVersion(): string
    {
        return (string) ($this->option('release-version') ?: NbaCalculationReleaseDefinition::SEMANTIC_VERSION);
    }
}
