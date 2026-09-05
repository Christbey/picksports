<?php

namespace App\Services\WCBB\Predictions;

use App\Services\Predictions\Basketball\CollegeBasketballReleaseDefinition;

class WcbbCalculationReleaseDefinition extends CollegeBasketballReleaseDefinition
{
    public const CALCULATOR_NAME = 'wcbb-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'wcbb-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'wcbb';
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
        return 136.0;
    }
}
