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
    $weather->forceFill(['updated_at' => now()->addHour()])->save();
    $snapshot = app(CfbInputSnapshotBuilder::class)->build($event->fresh(), $release);
    expect($snapshot->inputs['signal_context'])->not->toHaveKey('wind_speed_mph');
});
