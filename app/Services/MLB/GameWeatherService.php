<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GameWeatherService
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetch(Game $game): ?array
    {
        if ($this->isIndoorVenue($game)) {
            return [
                'provider' => 'open_meteo',
                'is_indoor' => true,
                'roof_status' => 'fixed_roof',
                'location_source' => 'indoor_venue',
                'observed_at' => $this->gameDateTime($game)?->toDateTimeString(),
            ];
        }

        $hasUnknownRetractableRoof = $this->hasUnknownRetractableRoof($game);

        $location = $this->resolveLocation($game);
        if ($location === null) {
            return null;
        }

        $dateTime = $this->gameDateTime($game);
        if (! $dateTime) {
            return null;
        }

        $date = $dateTime->toDateString();
        $response = Http::timeout(20)->get((string) config('services.open_meteo.forecast_url'), [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'hourly' => implode(',', [
                'temperature_2m',
                'apparent_temperature',
                'relative_humidity_2m',
                'precipitation',
                'precipitation_probability',
                'weather_code',
                'wind_speed_10m',
                'wind_gusts_10m',
                'wind_direction_10m',
            ]),
            'temperature_unit' => 'fahrenheit',
            'wind_speed_unit' => 'mph',
            'precipitation_unit' => 'inch',
            'timezone' => 'auto',
            'start_date' => $date,
            'end_date' => $date,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $hourIndex = $this->nearestHourlyIndex((array) data_get($payload, 'hourly.time', []), $dateTime);

        return [
            'provider' => 'open_meteo',
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_source' => $location['source'],
            'observed_at' => isset(data_get($payload, 'hourly.time', [])[$hourIndex])
                ? Carbon::parse(data_get($payload, "hourly.time.{$hourIndex}"))->toDateTimeString()
                : $dateTime->toDateTimeString(),
            'temperature_f' => $this->hourlyValue($payload, 'temperature_2m', $hourIndex),
            'feels_like_f' => $this->hourlyValue($payload, 'apparent_temperature', $hourIndex),
            'wind_speed_mph' => $this->hourlyValue($payload, 'wind_speed_10m', $hourIndex),
            'wind_gust_mph' => $this->hourlyValue($payload, 'wind_gusts_10m', $hourIndex),
            'wind_direction_degrees' => $this->hourlyValue($payload, 'wind_direction_10m', $hourIndex),
            'precipitation_probability' => $this->hourlyValue($payload, 'precipitation_probability', $hourIndex),
            'precipitation_inches' => $this->hourlyValue($payload, 'precipitation', $hourIndex),
            'humidity_percent' => $this->hourlyValue($payload, 'relative_humidity_2m', $hourIndex),
            'condition_code' => (string) $this->hourlyValue($payload, 'weather_code', $hourIndex),
            'is_indoor' => false,
            'roof_status' => $hasUnknownRetractableRoof ? 'unknown_retractable' : 'open_air',
            'raw_payload' => $payload,
        ];
    }

    /**
     * @return array{latitude:float,longitude:float,source:string}|null
     */
    protected function resolveLocation(Game $game): ?array
    {
        $coordinates = (array) config('mlb.prediction.actual_weather.venue_coordinates', []);
        $keys = array_filter([
            strtoupper((string) ($game->homeTeam?->abbreviation ?? '')),
            strtolower((string) ($game->venue_name ?? '')),
            strtolower(trim((string) ($game->venue_city ?? '').', '.(string) ($game->venue_state ?? ''))),
        ]);

        foreach ($keys as $key) {
            $match = $this->coordinateMatch($coordinates, (string) $key);
            if (is_array($match) && isset($match['latitude'], $match['longitude'])) {
                return [
                    'latitude' => (float) $match['latitude'],
                    'longitude' => (float) $match['longitude'],
                    'source' => 'configured',
                ];
            }
        }

        $query = trim((string) ($game->venue_city ?? '').' '.(string) ($game->venue_state ?? ''));
        if ($query === '') {
            return null;
        }

        $response = Http::timeout(15)->get((string) config('services.open_meteo.geocoding_url'), [
            'name' => $query,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);

        if (! $response->successful()) {
            return null;
        }

        $result = data_get($response->json(), 'results.0');
        if (! is_array($result) || ! isset($result['latitude'], $result['longitude'])) {
            return null;
        }

        return [
            'latitude' => (float) $result['latitude'],
            'longitude' => (float) $result['longitude'],
            'source' => 'geocoded_venue_city',
        ];
    }

    protected function gameDateTime(Game $game): ?Carbon
    {
        if (! $game->game_date) {
            return null;
        }

        return Carbon::parse($game->game_date->toDateString().' '.($game->game_time ?? '12:00:00'));
    }

    /**
     * @param  array<int, mixed>  $times
     */
    protected function nearestHourlyIndex(array $times, Carbon $target): int
    {
        $bestIndex = 0;
        $bestDiff = PHP_INT_MAX;

        foreach ($times as $index => $time) {
            $diff = abs(Carbon::parse((string) $time)->diffInMinutes($target, false));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIndex = (int) $index;
            }
        }

        return $bestIndex;
    }

    protected function hourlyValue(array $payload, string $key, int $index): mixed
    {
        return data_get($payload, "hourly.{$key}.{$index}");
    }

    protected function isIndoorVenue(Game $game): bool
    {
        $venue = strtolower((string) ($game->venue_name ?? ''));
        foreach ((array) config('mlb.prediction.actual_weather.indoor_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    protected function hasUnknownRetractableRoof(Game $game): bool
    {
        $venue = strtolower((string) ($game->venue_name ?? ''));
        foreach ((array) config('mlb.prediction.actual_weather.retractable_roof_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,array<string,mixed>>  $coordinates
     * @return array<string,mixed>|null
     */
    protected function coordinateMatch(array $coordinates, string $key): ?array
    {
        if (isset($coordinates[$key]) && is_array($coordinates[$key])) {
            return $coordinates[$key];
        }

        $normalizedKey = strtolower(trim($key));
        foreach ($coordinates as $configuredKey => $coordinate) {
            if (strtolower(trim((string) $configuredKey)) === $normalizedKey && is_array($coordinate)) {
                return $coordinate;
            }
        }

        return null;
    }
}
