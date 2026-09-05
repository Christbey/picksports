<?php

namespace App\Services\WCBB\Predictions;

use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;

class WcbbCalculator extends CanonicalBasketballCalculator
{
    protected function expectedSport(): string
    {
        return 'wcbb';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return WcbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
