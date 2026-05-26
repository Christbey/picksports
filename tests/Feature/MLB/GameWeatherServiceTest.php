<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\MLB\GameWeatherService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses()->group('mlb');

it('matches configured venue coordinates case-insensitively for mlb weather', function () {
    Config::set('mlb.prediction.actual_weather.venue_coordinates', [
        'Kauffman Stadium' => ['latitude' => 39.0517, 'longitude' => -94.4803],
    ]);

    $homeTeam = Team::factory()->create([
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Royals',
    ]);
    $awayTeam = Team::factory()->create([
        'abbreviation' => 'NYY',
        'location' => 'New York',
        'name' => 'Yankees',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'NYY @ KC',
        'game_date' => '2026-05-25',
        'game_time' => '14:40:00',
        'venue_name' => 'Kauffman Stadium',
        'venue_city' => 'Kansas City',
        'venue_state' => 'Missouri',
    ]);

    Http::fake(function (Request $request) {
        expect((float) $request['latitude'])->toBe(39.0517)
            ->and((float) $request['longitude'])->toBe(-94.4803);

        return Http::response([
            'hourly' => [
                'time' => ['2026-05-25T14:00', '2026-05-25T15:00'],
                'temperature_2m' => [78, 80],
                'apparent_temperature' => [79, 81],
                'relative_humidity_2m' => [55, 57],
                'precipitation' => [0, 0],
                'precipitation_probability' => [10, 10],
                'weather_code' => [1, 1],
                'wind_speed_10m' => [8, 9],
                'wind_gusts_10m' => [14, 15],
                'wind_direction_10m' => [180, 185],
            ],
        ]);
    });

    $weather = app(GameWeatherService::class)->fetch($game->fresh(['homeTeam', 'awayTeam']));

    expect($weather)->not->toBeNull()
        ->and($weather['location_source'])->toBe('configured')
        ->and((float) $weather['temperature_f'])->toBe(80.0)
        ->and((float) $weather['wind_speed_mph'])->toBe(9.0);
});
