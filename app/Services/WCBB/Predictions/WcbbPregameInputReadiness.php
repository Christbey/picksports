<?php

namespace App\Services\WCBB\Predictions;

use App\Actions\WCBB\GeneratePrediction;
use App\Models\CalculationRelease;
use App\Models\WCBB\Game;
use App\Services\Predictions\EstablishedModelTransport;

class WcbbPregameInputReadiness
{
    /** @return list<array{side: string, metric_id: int, reason: string}> */
    public function holds(Game $game, ?CalculationRelease $release = null): array
    {
        if ($release !== null && data_get($release->configuration, 'forecast.source') !== WcbbFullModelReleaseDefinition::FORECAST_SOURCE) {
            return [];
        }

        return app(EstablishedModelTransport::class)->usingConfiguration(
            (array) data_get($release?->configuration, 'forecast.configuration', []),
            fn () => app(GeneratePrediction::class)->metricInputHolds($game),
        );
    }
}
