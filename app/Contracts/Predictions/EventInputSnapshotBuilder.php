<?php

namespace App\Contracts\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\SportEvent;

interface EventInputSnapshotBuilder
{
    public function build(
        SportEvent $event,
        CalculationReleaseData $release,
    ): EventInputSnapshotData;
}
