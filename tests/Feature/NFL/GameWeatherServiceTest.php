<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\GameWeatherService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses()->group('nfl', 'weather');

it('geocodes the venue city and requests the venue-local forecast date', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create(['abbreviation' => 'SEA']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-09-10',
        'game_time' => '00:20:00',
        'venue_name' => 'Lumen Field',
        'venue_city' => 'Seattle',
        'venue_state' => 'WA',
    ]);

    Http::fake([
        'https://geocoding-api.open-meteo.com/*' => Http::response([
            'results' => [[
                'name' => 'Seattle',
                'latitude' => 47.60621,
                'longitude' => -122.33207,
                'timezone' => 'America/Los_Angeles',
            ]],
        ]),
        'https://api.open-meteo.com/*' => Http::response([
            'timezone' => 'America/Los_Angeles',
            'hourly' => [
                'time' => ['2026-09-09T17:00'],
                'temperature_2m' => [67.0],
                'apparent_temperature' => [66.0],
                'relative_humidity_2m' => [58.0],
                'precipitation' => [0.0],
                'precipitation_probability' => [5.0],
                'weather_code' => [1],
                'wind_speed_10m' => [7.0],
                'wind_gusts_10m' => [12.0],
                'wind_direction_10m' => [240],
            ],
        ]),
    ]);

    $weather = app(GameWeatherService::class)->fetch($game->fresh(['homeTeam', 'awayTeam']));

    expect($weather)->not->toBeNull()
        ->and($weather['location_source'])->toBe('geocoded_venue_city')
        ->and($weather['observed_at'])->toBe('2026-09-10 00:00:00')
        ->and($weather['temperature_f'])->toEqual(67.0);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'geocoding-api.open-meteo.com')
        && $request['name'] === 'Seattle'
    );
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.open-meteo.com/v1/forecast')
        && $request['start_date'] === '2026-09-09'
        && $request['timezone'] === 'America/Los_Angeles'
    );
});
