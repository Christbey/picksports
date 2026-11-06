<?php

namespace App\Services\CBB\Predictions;

class CbbPeerPriorReleaseDefinition extends CbbScoreMarginReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.3.0';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['forecast']['configuration']['cbb.prediction']['score_margin']['peer_prior']['enabled'] = true;

        return $configuration;
    }
}
