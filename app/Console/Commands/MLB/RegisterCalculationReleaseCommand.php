<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\MLB\Predictions\MlbCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'mlb:register-calculation-release {--release-version=1.0.0} {--draft} {--effective-at=} {--actor=artisan} {--reason=MLB canonical rules release registration.}';

    protected $description = 'Register the frozen canonical MLB pregame release';

    protected function registrarClass(): string
    {
        return MlbCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'MLB';
    }
}
