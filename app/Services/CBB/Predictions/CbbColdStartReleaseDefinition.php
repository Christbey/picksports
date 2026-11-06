<?php

namespace App\Services\CBB\Predictions;

class CbbColdStartReleaseDefinition extends CbbPeerPriorReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.4.0';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast']['configuration']['cbb.prediction']['score_margin']['cold_start_prior']['enabled'] = true;

        return $configuration;
    }
}
