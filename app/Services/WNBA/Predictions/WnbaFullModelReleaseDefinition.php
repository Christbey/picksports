<?php

namespace App\Services\WNBA\Predictions;

class WnbaFullModelReleaseDefinition extends WnbaCalculationReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.1.0';

    public const INPUT_SCHEMA_VERSION = 'wnba-pregame-v2';

    public const FORECAST_SOURCE = 'frozen_wnba_model_v1';

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
        return ['wnba.elo', 'wnba.prediction', 'wnba.season', 'wnba.metrics'];
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $frozen = collect(self::configurationKeys())->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
        $fallback = config('wnba.prediction.use_previous_season_metrics_fallback');
        $fallback = is_bool($fallback) ? $fallback : (bool) config('wnba.predictions.use_previous_season_metrics_fallback', false);
        $frozen['wnba.prediction']['use_previous_season_metrics_fallback'] = $fallback;
        $configuration['inputs']['use_previous_season_metrics_fallback'] = $fallback;

        return [...$configuration, 'forecast' => ['source' => self::FORECAST_SOURCE, 'configuration' => $frozen]];
    }
}
