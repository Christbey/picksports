<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'cfb:register-calculation-release {--release-version=1.1.0} {--draft} {--effective-at=} {--actor=artisan} {--reason=CFB canonical rules release registration.}';

    protected $description = 'Register the frozen canonical CFB pregame calculation release';

    protected function registrarClass(): string
    {
        return CfbCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'CFB';
    }
}
