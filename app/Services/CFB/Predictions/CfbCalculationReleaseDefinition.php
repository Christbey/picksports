<?php

namespace App\Services\CFB\Predictions;

use App\Services\Predictions\Football\FootballCalculationReleaseDefinition;

class CfbCalculationReleaseDefinition extends FootballCalculationReleaseDefinition
{
    public const CALCULATOR_NAME = 'cfb-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'cfb-pregame-v1';

    public const SEMANTIC_VERSION = '1.5.0';

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $configuration = parent::configuration();
        $configuration['inputs']['sample_aware_context'] = true;
        $configuration['inputs']['point_in_time_elo'] = true;
        $configuration['inputs']['auditable_feature_coverage'] = true;
        $configuration['inputs']['preserve_missing_metrics'] = true;
        $configuration['inputs']['personnel_evidence'] = true;
        $configuration['spread']['rating_baseline'] = 'fpi_points';

        return $configuration;
    }

    public function sport(): string
    {
        return 'cfb';
    }

    public function calculatorName(): string
    {
        return self::CALCULATOR_NAME;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    protected function homeFieldAdvantage(): float
    {
        return 55.0;
    }

    protected function defaultTeamPoints(): float
    {
        return 28.0;
    }

    protected function averageTotal(): float
    {
        return 56.0;
    }
}
