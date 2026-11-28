<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbWeatherTime;
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
            $start = MlbGameStart::for($game);
            $values = ['provider' => 'open_meteo', 'is_indoor' => true, 'roof_status' => 'fixed_roof',
                'location_source' => 'indoor_venue', 'observed_at' => MlbGameStart::canonical($game)?->toDateTimeString()];
            $values['raw_payload'] = $start === null ? [] : ['_mlb_weather_time' => MlbWeatherTime::capture($game, $start, $values)];

            return $values;
        }

        $dateTime = $this->gameDateTime($game);
        if ($dateTime === null) {
            return null;
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
            'timezone' => 'UTC',
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

        $hour = data_get($payload, "hourly.time.{$hourIndex}");
        if (! is_string($hour) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/D', $hour) !== 1) {
            return null;
        }
        try {
            $parsed = MlbGameStart::observed($hour.(strlen($hour) === 16 ? ':00Z' : 'Z'), []);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $forecastHour = Carbon::createFromTimestampUTC($parsed['start_epoch']);
        $values = [
            'provider' => 'open_meteo',
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_source' => $location['source'],
            'observed_at' => MlbGameStart::legacyTimestamp($forecastHour)?->toDateTimeString(),
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
        unset($payload['_mlb_weather_time']);
        $payload['_mlb_weather_time'] = MlbWeatherTime::capture($game, $forecastHour, $values);
        $values['raw_payload'] = $payload;

        return $values;
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
        return MlbGameStart::for($game);
    }

    /**
     * @param  array<int, mixed>  $times
     */
    protected function nearestHourlyIndex(array $times, Carbon $target): int
    {
        $bestIndex = 0;
        $bestDiff = PHP_INT_MAX;

        foreach ($times as $index => $time) {
            $diff = abs(Carbon::parse((string) $time, 'UTC')->diffInMinutes($target, false));
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
