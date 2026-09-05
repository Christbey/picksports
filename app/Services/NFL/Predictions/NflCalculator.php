<?php

namespace App\Services\NFL\Predictions;

use App\Services\Predictions\Football\CanonicalFootballCalculator;

class NflCalculator extends CanonicalFootballCalculator
{
    protected function expectedSport(): string
    {
        return 'nfl';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return NflCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
