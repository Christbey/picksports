<?php

namespace App\Services\NFL\Predictions;

use App\Services\Predictions\Football\FootballCalculationReleaseDefinition;

class NflCalculationReleaseDefinition extends FootballCalculationReleaseDefinition
{
    public const CALCULATOR_NAME = 'nfl-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'nfl-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'nfl';
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

    protected function homeFieldAdvantage(): float
    {
        return 45.0;
    }

    protected function defaultTeamPoints(): float
    {
        return 22.5;
    }

    protected function averageTotal(): float
    {
        return 45.0;
    }
}
