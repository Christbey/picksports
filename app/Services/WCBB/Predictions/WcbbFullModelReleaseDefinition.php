<?php

namespace App\Services\WCBB\Predictions;

class WcbbFullModelReleaseDefinition extends WcbbCalculationReleaseDefinition
{
    public const SEMANTIC_VERSION = '1.1.0';

    public const METRIC_RECOVERY_VERSION = '1.1.1';

    public const INPUT_SCHEMA_VERSION = 'wcbb-pregame-v2';

    public const FORECAST_SOURCE = 'frozen_wcbb_model_v1';

    public function __construct(private readonly string $version = self::SEMANTIC_VERSION) {}

    public function semanticVersion(): string
    {
        return $this->version;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    /** @return list<string> */
    public static function configurationKeys(): array
    {
        return ['wcbb.elo', 'wcbb.prediction', 'wcbb.season', 'wcbb.metrics'];
    }

    public function configuration(): array
    {
        $configuration = parent::configuration();
        $frozen = collect(self::configurationKeys())->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
        $fallback = config('wcbb.prediction.use_previous_season_metrics_fallback');
        $fallback = is_bool($fallback) ? $fallback : (bool) config('wcbb.predictions.use_previous_season_metrics_fallback', false);
        $recoverInvalidMetrics = $this->version === self::METRIC_RECOVERY_VERSION;
        if ($recoverInvalidMetrics) {
            $fallback = true;
            $frozen['wcbb.prediction']['recover_invalid_team_metrics'] = true;
            $configuration['inputs']['recover_invalid_team_metrics'] = true;
        }
        $frozen['wcbb.prediction']['use_previous_season_metrics_fallback'] = $fallback;
        $configuration['inputs']['use_previous_season_metrics_fallback'] = $fallback;

        return [...$configuration, 'forecast' => ['source' => self::FORECAST_SOURCE, 'configuration' => $frozen]];
    }
}
