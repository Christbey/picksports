<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'nfl:register-calculation-release {--release-version=1.0.0} {--draft} {--effective-at=} {--actor=artisan} {--reason=NFL canonical rules release registration.}';

    protected $description = 'Register the frozen canonical NFL pregame calculation release';

    protected function registrarClass(): string
    {
        return NflCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'NFL';
    }
}
