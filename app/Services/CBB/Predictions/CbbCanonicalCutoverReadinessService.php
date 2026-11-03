<?php

namespace App\Services\CBB\Predictions;

use App\Models\CBB\Game;
use App\Services\CBB\CbbPregameEligibility;
use App\Services\Predictions\Basketball\EstablishedBasketballCutoverReadiness;

class CbbCanonicalCutoverReadinessService
{
    public function __construct(private readonly EstablishedBasketballCutoverReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report(
            sport: 'cbb', gameModel: Game::class,
            calculatorName: CbbCalculationReleaseDefinition::CALCULATOR_NAME,
            legacySchema: CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            fullSchema: CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            forecastSource: CbbFullModelReleaseDefinition::FORECAST_SOURCE,
            season: $season,
            eligible: fn ($game) => app(CbbPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam),
            identityMatches: fn ($game) => app(CbbPregameEligibility::class)->identityMatches($game),
        );
    }
}
