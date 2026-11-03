<?php

namespace App\Services\NBA\Predictions;

class NbaFullModelReleaseDefinition extends NbaCalculationReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.1.1';

    public const INPUT_SCHEMA_VERSION = 'nba-pregame-v2';

    public const FORECAST_SOURCE = 'frozen_nba_model_v1';

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    /** @return list<string> */
    public static function configurationKeys(): array
    {
        return ['nba.elo', 'nba.prediction', 'nba.season', 'nba.possession_coefficient'];
    }

    public function configuration(): array
    {
        return [...parent::configuration(), 'forecast' => [
            'source' => self::FORECAST_SOURCE,
            'configuration' => collect(self::configurationKeys())
                ->mapWithKeys(fn (string $key): array => [$key => config($key)])->all(),
        ]];
    }
}
