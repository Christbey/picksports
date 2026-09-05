<?php

namespace App\Services\WNBA\Predictions;

use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;

class WnbaCalculator extends CanonicalBasketballCalculator
{
    protected function expectedSport(): string
    {
        return 'wnba';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
