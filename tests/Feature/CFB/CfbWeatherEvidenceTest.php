<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Models\CFB\Game;
use App\Models\CFB\GameWeather;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\CfbGameWeatherService;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbInputSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('uses the actual kickoff instant to request and select UTC forecast hours across a date boundary', function () {
    config()->set('app.timezone', 'America/Chicago');
    config()->set('services.open_meteo.forecast_url', 'https://weather.test/forecast');
    config()->set('cfb.predictions.game_context.weather.venue_coordinates', ['ABC' => ['latitude' => 40, 'longitude' => -75]]);
    $home = Team::factory()->create(['abbreviation' => 'ABC']);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => CarbonImmutable::parse('2026-09-20 01:40:00', 'UTC')]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id,
        'away_team_id' => Team::factory()->create()->id, 'venue_name' => 'Outdoor field', 'game_date' => '2026-09-19', 'game_time' => '20:40:00']);
    Http::fake(['*weather.test*' => function ($request) {
        expect($request['timezone'])->toBe('UTC')->and($request['start_date'])->toBe('2026-09-20');

        return Http::response(['timezone' => 'UTC', 'hourly' => ['time' => ['2026-09-20T01:00', '2026-09-20T02:00'],
            'temperature_2m' => [61, 62], 'wind_speed_10m' => [11, 22]]]);
    }]);
    $result = app(CfbGameWeatherService::class)->fetch($game);
    expect($result['temperature_f'])->toBe(62)->and($result['wind_speed_mph'])->toBe(22)
        ->and($result['observed_at'])->toBe('2026-09-19 21:00:00');
});

it('includes a kickoff-valid forecast received before capture but excludes subsequently received data', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-19 10:00:00'));
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'starts_at' => now()->addHours(4)]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'week' => 3,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-19']);
    $weather = GameWeather::create(['game_id' => $game->id, 'provider' => 'open_meteo', 'observed_at' => $event->starts_at,
        'temperature_f' => 80, 'wind_speed_mph' => 25, 'is_indoor' => false]);
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $config['football_signals']['enabled'] = true;
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', 'test', 'test', 'test', 'cfb-pregame-v1', $config);
    $snapshot = app(CfbInputSnapshotBuilder::class)->build($event, $release);
    expect($snapshot->inputs['signal_context']['wind_speed_mph'])->toBe(25.0)
        ->and($snapshot->inputs['signal_context']['weather_evidence']['forecast_valid_at'])->toBe($event->starts_at->toIso8601String())
        ->and($snapshot->sourceTimestamps['signal_weather'])->toBe($weather->updated_at->toIso8601String())
        ->and($snapshot->latestSourceAvailableAt->lte($snapshot->capturedAt))->toBeTrue();
    $weather->update(['location_source' => 'geocoded_venue_city']);
    expect(app(CfbInputSnapshotBuilder::class)->build($event->fresh(), $release)->inputs['signal_context'])->not->toHaveKey('wind_speed_mph');
    $weather->update(['location_source' => 'geocoded_venue_city_qualified']);
    $refreshed = app(CfbInputSnapshotBuilder::class)->build($event->fresh(), $release);
    expect($refreshed->inputs['signal_context']['weather_evidence']['location_source'])->toBe('geocoded_venue_city_qualified');
    $weather->forceFill(['updated_at' => now()->addHour()])->save();
    $snapshot = app(CfbInputSnapshotBuilder::class)->build($event->fresh(), $release);
    expect($snapshot->inputs['signal_context'])->not->toHaveKey('wind_speed_mph');
});

it('continues weather refreshes after one game times out and reports a partial failure', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $first = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'game_time' => '12:00:00', 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $second = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'game_time' => '15:00:00', 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $this->mock(CfbGameWeatherService::class)->shouldReceive('fetch')->twice()->andReturnUsing(function ($game) use ($first) {
        if ($game->id === $first->id) {
            throw new ConnectionException('Forecast request timed out');
        }

        return ['provider' => 'open_meteo', 'temperature_f' => 70, 'is_indoor' => false];
    });
    $this->artisan('cfb:sync-game-weather', ['--season' => 2026, '--force' => true])
        ->expectsOutput("Weather refresh failed for CFB game {$first->id}; continuing remaining games.")
        ->expectsOutput('CFB weather sync complete. Created 1, updated 0, skipped 0, failed 1.')->assertFailed();
    expect(GameWeather::where('game_id', $first->id)->exists())->toBeFalse()
        ->and((float) GameWeather::where('game_id', $second->id)->firstOrFail()->temperature_f)->toBe(70.0);
});

it('qualifies geocoding with the documented comma-separated state parameter', function () {
    config()->set('cfb.predictions.game_context.weather.venue_coordinates', []);
    config()->set('services.open_meteo.geocoding_url', 'https://geo.test/search');
    config()->set('services.open_meteo.forecast_url', 'https://weather.test/forecast');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'venue_name' => 'Outdoor field', 'venue_city' => 'Oxford', 'venue_state' => 'MS']);
    Http::fake(['*geo.test*' => function ($request) {
        expect($request['name'])->toBe('Oxford, MS');

        return Http::response(['results' => [['latitude' => 34.3665, 'longitude' => -89.5192, 'name' => 'Oxford', 'admin1' => 'Mississippi']]]);
    }, '*weather.test*' => Http::response(['hourly' => ['time' => ['2026-09-19T12:00'], 'temperature_2m' => [80]]])]);
    $result = app(CfbGameWeatherService::class)->fetch($game);
    expect($result['location_source'])->toBe('geocoded_venue_city_qualified')->and($result['latitude'])->toBe(34.3665);
});
