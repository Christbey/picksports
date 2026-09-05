<?php

namespace App\Services\CFB\Predictions;

use App\Services\Predictions\Football\CanonicalFootballCalculator;

class CfbCalculator extends CanonicalFootballCalculator
{
    protected function expectedSport(): string
    {
        return 'cfb';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
