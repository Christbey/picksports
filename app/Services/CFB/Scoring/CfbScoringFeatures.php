<?php

namespace App\Services\CFB\Scoring;

use App\Services\CFB\Predictions\CfbForecastCoordinator;

/** Shared frozen feature vocabulary for PHP inference and offline fitting. */
class CfbScoringFeatures
{
    public const POLICY = 'cfb-efficiency-context-v2';

    public function independentCatalog(array $catalog): array
    {
        $coordinator = new CfbForecastCoordinator;

        return array_filter($catalog, fn ($rule) => ! $coordinator->marketDependent($rule));
    }

    public function capture(array $inputs): array
    {
        $result = [];
        foreach (['home', 'away'] as $side) {
            $injuries = data_get($inputs, $side.'.injuries');
            $quarterback = data_get($inputs, $side.'.personnel.components.quarterback');
            $returning = data_get($inputs, $side.'.personnel.components.returning_production');
            $talent = data_get($inputs, $side.'.personnel.components.talent');
            $changed = ($quarterback['status'] ?? null) === 'verified'
                ? data_get($quarterback, 'values.observed_primary_passer_changed') : null;
            $result[$side] = [
                'qb_changed' => is_bool($changed) ? (int) $changed : null,
                // An empty injury feed is unknown, never a confirmed healthy roster.
                'out_records' => is_array($injuries) && $injuries !== [] ? count(array_filter($injuries, fn ($i) => ($i['status'] ?? '') === 'out')) : null,
                'questionable_records' => is_array($injuries) && $injuries !== [] ? count(array_filter($injuries, fn ($i) => ($i['status'] ?? '') === 'questionable')) : null,
                'rest_travel_fatigue' => data_get($inputs, $side.'.metrics.rest_travel_fatigue'),
                'returning_usage' => ($returning['status'] ?? null) === 'verified' ? data_get($returning, 'values.usage') : null,
                'talent' => ($talent['status'] ?? null) === 'verified' ? data_get($talent, 'values.talent') : null,
                'wind_speed_mph' => data_get($inputs, 'signal_context.is_indoor') === true ? null : data_get($inputs, 'signal_context.wind_speed_mph'),
                'precipitation_inches' => data_get($inputs, 'signal_context.is_indoor') === true ? null : data_get($inputs, 'signal_context.precipitation_inches'),
            ];
        }

        return array_map(fn ($values) => array_map(fn ($value) => is_string($value) && is_numeric($value) ? (float) $value : $value, $values), $result);
    }

    public function context(array $game, string $side): array
    {
        $result = [];
        foreach (['qb_changed', 'out_records', 'questionable_records', 'rest_travel_fatigue',
            'returning_usage', 'talent', 'wind_speed_mph', 'precipitation_inches'] as $key) {
            $value = $game['context'][$side][$key] ?? null;
            $known = is_numeric($value) && is_finite((float) $value);
            $result['context:'.$key.':missing'] = (float) ! $known;
            if ($known) {
                $result['context:'.$key] = (float) $value;
            }
        }

        return $result;
    }

    public function stadium(array $game, string $side): array
    {
        return empty($game['neutral']) && ! empty($game['venue'])
            ? ['venue:'.$game['venue'].':'.$side => 1.0] : [];
    }

    public function pace(array $game, string $side): array
    {
        $other = $side === 'home' ? 'away' : 'home';

        return ['team:'.$game[$side.'_id'] => 1.0, 'opponent:'.$game[$other.'_id'] => 1.0];
    }
}
