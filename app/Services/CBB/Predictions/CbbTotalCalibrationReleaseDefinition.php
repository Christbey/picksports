<?php

namespace App\Services\CBB\Predictions;

class CbbTotalCalibrationReleaseDefinition extends CbbRosterReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.6.0';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast']['configuration']['cbb.prediction']['total_calibration']['high_total_slope'] = 0.0;

        return $configuration;
    }
}
