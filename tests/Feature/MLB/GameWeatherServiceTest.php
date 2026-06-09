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

it('fetches weather for retractable roof parks while preserving unknown roof context', function () {
    Config::set('mlb.prediction.actual_weather.venue_coordinates', [
        'loanDepot park' => ['latitude' => 25.7781, 'longitude' => -80.2197],
    ]);
    Config::set('mlb.prediction.actual_weather.retractable_roof_venue_keywords', ['loanDepot park']);

    $homeTeam = Team::factory()->create([
        'abbreviation' => 'MIA',
        'location' => 'Miami',
        'name' => 'Marlins',
    ]);
    $awayTeam = Team::factory()->create([
        'abbreviation' => 'ARI',
        'location' => 'Arizona',
        'name' => 'Diamondbacks',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'ARI @ MIA',
        'game_date' => '2026-06-09',
        'game_time' => '18:40:00',
        'venue_name' => 'loanDepot park',
        'venue_city' => 'Miami',
        'venue_state' => 'Florida',
    ]);

    Http::fake(fn (Request $request) => Http::response([
        'hourly' => [
            'time' => ['2026-06-09T18:00', '2026-06-09T19:00'],
            'temperature_2m' => [84, 83],
            'apparent_temperature' => [88, 87],
            'relative_humidity_2m' => [70, 72],
            'precipitation' => [0, 0],
            'precipitation_probability' => [15, 20],
            'weather_code' => [2, 2],
            'wind_speed_10m' => [7, 8],
            'wind_gusts_10m' => [12, 13],
            'wind_direction_10m' => [150, 155],
        ],
    ]));

    $weather = app(GameWeatherService::class)->fetch($game->fresh(['homeTeam', 'awayTeam']));

    expect($weather)->not->toBeNull()
        ->and($weather['location_source'])->toBe('configured')
        ->and($weather['roof_status'])->toBe('unknown_retractable')
        ->and((float) $weather['temperature_f'])->toBe(83.0)
        ->and((float) $weather['wind_speed_mph'])->toBe(8.0);
});

it('uses configured Las Vegas Ballpark coordinates for mlb weather', function () {
    $homeTeam = Team::factory()->create([
        'abbreviation' => 'ATH',
        'location' => 'Athletics',
        'name' => 'Athletics',
    ]);
    $awayTeam = Team::factory()->create([
        'abbreviation' => 'MIL',
        'location' => 'Milwaukee',
        'name' => 'Brewers',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'MIL @ ATH',
        'game_date' => '2026-06-09',
        'game_time' => '21:05:00',
        'venue_name' => 'Las Vegas Ballpark',
        'venue_city' => 'Las Vegas',
        'venue_state' => 'Nevada',
    ]);

    Http::fake(function (Request $request) {
        expect((float) $request['latitude'])->toBe(36.1595)
            ->and((float) $request['longitude'])->toBe(-115.3300);

        return Http::response([
            'hourly' => [
                'time' => ['2026-06-09T21:00'],
                'temperature_2m' => [94],
                'apparent_temperature' => [92],
                'relative_humidity_2m' => [18],
                'precipitation' => [0],
                'precipitation_probability' => [0],
                'weather_code' => [0],
                'wind_speed_10m' => [11],
                'wind_gusts_10m' => [18],
                'wind_direction_10m' => [210],
            ],
        ]);
    });

    $weather = app(GameWeatherService::class)->fetch($game->fresh(['homeTeam', 'awayTeam']));

    expect($weather)->not->toBeNull()
        ->and($weather['location_source'])->toBe('configured')
        ->and($weather['roof_status'])->toBe('open_air')
        ->and((float) $weather['temperature_f'])->toBe(94.0);
});

it('keeps common retractable roof mlb venues in configured weather coordinates', function () {
    $coordinates = (array) config('mlb.prediction.actual_weather.venue_coordinates');

    expect($coordinates)
        ->toHaveKeys([
            'American Family Field',
            'Busch Stadium',
            'Chase Field',
            'Daikin Park',
            'Globe Life Field',
            'loanDepot park',
            'Rogers Centre',
            'T-Mobile Park',
            'Truist Park',
        ]);
});
