<?php

namespace App\Services\NBA\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;
use App\Services\Predictions\Basketball\EstablishedBasketballModel;

class NbaCalculator extends CanonicalBasketballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return parent::calculate($snapshot, $release);
        }

        return app(EstablishedBasketballModel::class)->calculate(
            $snapshot, $release, 'nba', NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, NbaFullModelReleaseDefinition::FORECAST_SOURCE,
        );
    }

    protected function expectedSport(): string
    {
        return 'nba';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
