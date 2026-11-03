<?php

namespace App\Services\CBB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;
use App\Services\Predictions\Basketball\EstablishedBasketballModel;

class CbbCalculator extends CanonicalBasketballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return parent::calculate($snapshot, $release);
        }

        return app(EstablishedBasketballModel::class)->calculate(
            $snapshot, $release, 'cbb', CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, CbbFullModelReleaseDefinition::FORECAST_SOURCE,
        );
    }

    protected function expectedSport(): string
    {
        return 'cbb';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
