<?php

namespace App\Services\WCBB\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Models\WCBB\Game;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Predictions\EstablishedModelCutoverReadiness;

class WcbbCanonicalCutoverReadinessService
{
    public function __construct(private readonly EstablishedModelCutoverReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        $release = null;
        try {
            $release = app(CalculationReleaseSelector::class)->select(new SportEvent(['sport' => 'wcbb']), 'pregame');
        } catch (PredictionLifecycleException) {
            // The shared report records release availability separately.
        }
        $inputHolds = [];
        $report = $this->readiness->report(
            sport: 'wcbb', gameModel: Game::class,
            calculatorName: WcbbCalculationReleaseDefinition::CALCULATOR_NAME,
            legacySchema: WcbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            fullSchema: WcbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            forecastSource: WcbbFullModelReleaseDefinition::FORECAST_SOURCE,
            season: $season,
            eligible: function ($game) use ($release, &$inputHolds): bool {
                if (! app(CollegeBasketballPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam)) {
                    return false;
                }
                $reasons = app(WcbbPregameInputReadiness::class)->holds($game, $release);
                if ($reasons !== []) {
                    $inputHolds[$game->id] = $reasons;

                    return false;
                }

                return true;
            },
            identityMatches: fn ($game) => app(CollegeBasketballPregameEligibility::class)->identityMatches($game),
        );
        $report['held_input_game_ids'] = array_keys($inputHolds);
        $report['input_hold_reasons'] = $inputHolds;
        $report['held_placeholder_game_ids'] = array_values(array_diff($report['held_placeholder_game_ids'], array_keys($inputHolds)));

        return $report;
    }
}
