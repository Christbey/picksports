<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;

class PredictionWeatherContext
{
    public function forPrediction(CanonicalPrediction $prediction, ?Game $game): array
    {
        $signal = data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'signal_context', []);
        $evidence = $signal['weather_evidence'] ?? null;
        if ($evidence) {
            return ['available' => true, 'source' => 'model_snapshot',
                'provider' => $evidence['provider'] ?? null,
                'forecast_valid_at' => $evidence['forecast_valid_at'] ?? null,
                'received_at' => $evidence['available_at'] ?? null,
                ...collect($signal)->only(['temperature_f', 'wind_speed_mph', 'wind_gust_mph', 'precipitation_inches', 'is_indoor'])->all()];
        }

        $weather = $game?->weather;
        if (! $weather) {
            return ['available' => false, 'source' => 'missing'];
        }

        return ['available' => true, 'source' => 'latest_forecast',
            'provider' => $weather->provider,
            'forecast_valid_at' => $weather->observed_at?->toIso8601String(),
            'received_at' => $weather->updated_at?->toIso8601String(),
            ...$weather->only(['temperature_f', 'wind_speed_mph', 'wind_gust_mph', 'precipitation_inches', 'precipitation_probability', 'is_indoor'])];
    }
}
