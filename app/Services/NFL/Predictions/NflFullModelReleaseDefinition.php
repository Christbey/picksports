<?php

namespace App\Services\NFL\Predictions;

class NflFullModelReleaseDefinition extends NflCalculationReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.3.0';

    public const FORECAST_SOURCE = 'frozen_nfl_model_v1';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        return [...parent::configuration(), 'forecast' => ['source' => self::FORECAST_SOURCE]];
    }
}
