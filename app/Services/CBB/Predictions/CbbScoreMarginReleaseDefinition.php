<?php

namespace App\Services\CBB\Predictions;

class CbbScoreMarginReleaseDefinition extends CbbFullModelReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.2.1';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast']['configuration']['cbb.prediction']['score_margin']['enabled'] = true;

        return $configuration;
    }
}
