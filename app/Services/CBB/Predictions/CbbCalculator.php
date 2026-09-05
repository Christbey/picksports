<?php

namespace App\Services\CBB\Predictions;

use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;

class CbbCalculator extends CanonicalBasketballCalculator
{
    protected function expectedSport(): string
    {
        return 'cbb';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
