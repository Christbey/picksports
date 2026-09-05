<?php

namespace App\Contracts\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;

interface SportCalculator
{
    public function calculate(
        EventInputSnapshotData $snapshot,
        CalculationReleaseData $release,
    ): PredictionOutput;
}
