<?php

namespace App\Services\CBB\Predictions;

class CbbRosterReleaseDefinition extends CbbColdStartReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.5.0';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast']['configuration']['cbb.prediction']['roster']['enabled'] = true;
        $configuration['forecast']['configuration']['cbb.prediction']['roster']['apply_adjustment'] = false;

        return $configuration;
    }
}
