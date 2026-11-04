<?php

namespace App\Services\NBA\Predictions;

use App\Models\NBA\Game;
use App\Services\Predictions\EstablishedModelCutoverReadiness;

class NbaCanonicalCutoverReadinessService
{
    public function __construct(private readonly EstablishedModelCutoverReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report(
            sport: 'nba', gameModel: Game::class,
            calculatorName: NbaCalculationReleaseDefinition::CALCULATOR_NAME,
            legacySchema: NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            fullSchema: NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            forecastSource: NbaFullModelReleaseDefinition::FORECAST_SOURCE,
            season: $season,
        );
    }
}
