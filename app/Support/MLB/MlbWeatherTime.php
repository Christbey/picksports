<?php

namespace App\Support\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class MlbWeatherTime
{
    public const POLICY = 'mlb-weather-forecast-receipt-v1';

    public static function capture(Game $game, CarbonInterface $forecastHour, array $values): array
    {
        $body = ['policy' => self::POLICY, 'game_id' => (int) $game->id, 'game_context_hash' => $game->start_context_hash,
            'venue_hash' => self::venue($game), 'forecast_hour_epoch' => $forecastHour->getTimestamp(),
            'received_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'values_hash' => self::values($values)];

        return $body + ['hash' => app(CanonicalPayloadHasher::class)->hash($body)];
    }

    public static function marker(Game $game, GameWeather $weather): ?array
    {
        try {
            $marker = data_get($weather->raw_payload, '_mlb_weather_time');
            if (! is_array($marker) || ! is_string($marker['hash'] ?? null) || MlbGameStart::for($game) === null) {
                return null;
            }
            $body = $marker;
            unset($body['hash']);
            if (! hash_equals($marker['hash'], app(CanonicalPayloadHasher::class)->hash($body))
                || ($body['policy'] ?? null) !== self::POLICY || ($body['game_id'] ?? null) !== (int) $game->id
                || (int) $weather->game_id !== (int) $game->id || ($body['game_context_hash'] ?? null) !== $game->start_context_hash
                || ($body['venue_hash'] ?? null) !== self::venue($game) || ($body['values_hash'] ?? null) !== self::values($weather->getAttributes())
                || ! is_int($body['forecast_hour_epoch'] ?? null) || ! is_string($body['received_at_utc'] ?? null)) {
                return null;
            }
            CarbonImmutable::parse($body['received_at_utc'], 'UTC');

            return $marker;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function available(Game $game, GameWeather $weather, CarbonInterface $capturedAt): bool
    {
        $marker = self::marker($game, $weather);
        if ($marker === null) {
            return false;
        }
        $received = CarbonImmutable::parse($marker['received_at_utc'], 'UTC');

        return $received->lte($capturedAt) && $received->lt(MlbGameStart::for($game)) && MlbGameStart::observedBy($game, $received);
    }

    private static function venue(Game $game): string
    {
        return app(CanonicalPayloadHasher::class)->hash([$game->venue_name, $game->venue_city, $game->venue_state]);
    }

    private static function values(array $values): string
    {
        $normalized = [];
        foreach (['latitude' => 6, 'longitude' => 6, 'temperature_f' => 2, 'feels_like_f' => 2, 'wind_speed_mph' => 2,
            'wind_gust_mph' => 2, 'precipitation_probability' => 2, 'precipitation_inches' => 3, 'humidity_percent' => 2] as $key => $scale) {
            $normalized[$key] = isset($values[$key]) ? number_format((float) $values[$key], $scale, '.', '') : null;
        }
        $normalized['wind_direction_degrees'] = isset($values['wind_direction_degrees']) ? (int) $values['wind_direction_degrees'] : null;
        $normalized['condition_code'] = isset($values['condition_code']) ? (string) $values['condition_code'] : null;
        $normalized['is_indoor'] = (bool) ($values['is_indoor'] ?? false);
        $normalized['roof_status'] = $values['roof_status'] ?? null;

        return app(CanonicalPayloadHasher::class)->hash($normalized);
    }
}
