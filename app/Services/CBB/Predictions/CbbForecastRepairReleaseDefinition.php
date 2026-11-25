<?php

namespace App\Services\CBB\Predictions;

class CbbForecastRepairReleaseDefinition extends CbbTotalCalibrationReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.7.0';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        // Retire the unvalidated additive correction with inverted defensive effects.
        $configuration['forecast']['configuration']['cbb.prediction']['total_factor_adjustments_enabled'] = false;
        $configuration['forecast']['configuration']['cbb.prediction']['total_calibration']['base_adjustment'] = 0.0;

        return $configuration;
    }
}
