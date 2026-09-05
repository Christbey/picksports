<?php

namespace App\Services\CBB\Predictions;

use App\Services\Predictions\Basketball\CollegeBasketballReleaseDefinition;

class CbbCalculationReleaseDefinition extends CollegeBasketballReleaseDefinition
{
    public const CALCULATOR_NAME = 'cbb-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'cbb-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'cbb';
    }

    public function calculatorName(): string
    {
        return self::CALCULATOR_NAME;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    protected function averageTotal(): float
    {
        return 142.0;
    }
}
