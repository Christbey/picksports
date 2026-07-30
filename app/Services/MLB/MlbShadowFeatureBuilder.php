<?php

namespace App\Services\MLB;

use App\Models\PredictionFeatureSnapshot;

class MlbShadowFeatureBuilder
{
    /**
     * @return array<string, int|float|null>
     */
    public function build(PredictionFeatureSnapshot $snapshot): array
    {
        $features = [];
        foreach ((array) $snapshot->features as $name => $value) {
            if (! is_string($name) || (! is_numeric($value) && $value !== null)) {
                continue;
            }
            $featureName = str_starts_with($name, 'feature_') ? $name : 'feature_'.$name;
            $features[$featureName] = $value === null ? null : (float) $value;
        }

        $this->setNumeric(
            $features,
            'feature_model_win_probability',
            data_get($snapshot->outputs, 'win_probability'),
        );
        $this->setNumeric(
            $features,
            'feature_model_predicted_margin',
            data_get($snapshot->outputs, 'predicted_spread'),
        );
        $this->setNumeric(
            $features,
            'feature_model_predicted_total',
            data_get($snapshot->outputs, 'predicted_total'),
        );
        $this->setNumeric(
            $features,
            'feature_market_home_spread',
            data_get($snapshot->outputs, 'market_spread')
                ?? data_get($snapshot->market_context, 'market_home_margin'),
        );
        $this->setNumeric(
            $features,
            'feature_market_total',
            data_get($snapshot->outputs, 'market_total')
                ?? data_get($snapshot->market_context, 'market_total'),
        );
        $this->setNumeric(
            $features,
            'feature_market_home_win_probability',
            data_get($snapshot->market_context, 'home_no_vig_probability')
                ?? data_get($snapshot->market_context, 'market_home_win_probability'),
        );

        ksort($features);

        return $features;
    }

    /**
     * @param  array<string, int|float|null>  $features
     */
    private function setNumeric(array &$features, string $name, mixed $value): void
    {
        if (is_numeric($value)) {
            $features[$name] = (float) $value;
        }
    }
}
