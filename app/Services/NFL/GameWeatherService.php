<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class GameWeatherService
{
    public function __construct(
        protected SportsDateWindowService $dateWindows,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(Game $game): ?array
    {
        if ($this->isIndoorVenue($game)) {
            return [
                'provider' => 'open_meteo',
                'is_indoor' => true,
                'location_source' => 'indoor_venue',
                'observed_at' => $this->gameDateTime($game)?->toDateTimeString(),
            ];
        }

        $location = $this->resolveLocation($game);
        if ($location === null) {
            return null;
        }

        $dateTime = $this->gameDateTime($game);
        if (! $dateTime) {
            return null;
        }

        $forecastTimezone = $location['timezone'] ?? $this->dateWindows->timezone();
        $date = $dateTime->copy()->setTimezone($forecastTimezone)->toDateString();
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
            'timezone' => $forecastTimezone,
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

        $payloadTimezone = (string) (data_get($payload, 'timezone') ?: $forecastTimezone);
        $localGameTime = $dateTime->copy()->setTimezone($payloadTimezone);
        $hourIndex = $this->nearestHourlyIndex(
            (array) data_get($payload, 'hourly.time', []),
            $localGameTime,
            $payloadTimezone,
        );

        return [
            'provider' => 'open_meteo',
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location_source' => $location['source'],
            'observed_at' => isset(data_get($payload, 'hourly.time', [])[$hourIndex])
                ? Carbon::parse(data_get($payload, "hourly.time.{$hourIndex}"), $payloadTimezone)->utc()->toDateTimeString()
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
            'raw_payload' => $payload,
        ];
    }

    /**
     * @return array{latitude:float,longitude:float,source:string,timezone?:string}|null
     */
    protected function resolveLocation(Game $game): ?array
    {
        $coordinates = (array) config('nfl.predictions.actual_weather.venue_coordinates', []);
        $keys = array_filter([
            strtoupper((string) ($game->homeTeam?->abbreviation ?? '')),
            strtolower((string) ($game->venue_name ?? '')),
            strtolower(trim((string) ($game->venue_city ?? '').', '.(string) ($game->venue_state ?? ''))),
        ]);

        foreach ($keys as $key) {
            $match = $coordinates[$key] ?? null;
            if (is_array($match) && isset($match['latitude'], $match['longitude'])) {
                $location = [
                    'latitude' => (float) $match['latitude'],
                    'longitude' => (float) $match['longitude'],
                    'source' => 'configured',
                ];

                if (isset($match['timezone']) && is_string($match['timezone'])) {
                    $location['timezone'] = $match['timezone'];
                }

                return $location;
            }
        }

        $query = trim((string) ($game->venue_city ?? ''));
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

        $location = [
            'latitude' => (float) $result['latitude'],
            'longitude' => (float) $result['longitude'],
            'source' => 'geocoded_venue_city',
        ];

        if (isset($result['timezone']) && is_string($result['timezone'])) {
            $location['timezone'] = $result['timezone'];
        }

        return $location;
    }

    protected function gameDateTime(Game $game): ?Carbon
    {
        if (! $game->game_date) {
            return null;
        }

        return $this->dateWindows
            ->gameDateTimeUtc($game->game_date, $game->game_time ?? '12:00:00')
            ?->toMutable();
    }

    /**
     * @param  array<int, mixed>  $times
     */
    protected function nearestHourlyIndex(array $times, Carbon $target, string $timezone): int
    {
        $bestIndex = 0;
        $bestDiff = PHP_INT_MAX;

        foreach ($times as $index => $time) {
            $diff = abs(Carbon::parse((string) $time, $timezone)->diffInMinutes($target, false));
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
        foreach ((array) config('nfl.predictions.contextual_factors.indoor_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }
}
