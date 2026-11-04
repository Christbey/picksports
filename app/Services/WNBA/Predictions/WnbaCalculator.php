<?php

namespace App\Services\WNBA\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;
use App\Services\Predictions\EstablishedModelTransport;

class WnbaCalculator extends CanonicalBasketballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return parent::calculate($snapshot, $release);
        }

        return app(EstablishedModelTransport::class)->calculate(
            $snapshot, $release, 'wnba', WnbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, WnbaFullModelReleaseDefinition::FORECAST_SOURCE,
        );
    }

    protected function expectedSport(): string
    {
        return 'wnba';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
