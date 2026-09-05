<?php

namespace App\Services\NBA\Predictions;

use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;

class NbaCalculator extends CanonicalBasketballCalculator
{
    protected function expectedSport(): string
    {
        return 'nba';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
