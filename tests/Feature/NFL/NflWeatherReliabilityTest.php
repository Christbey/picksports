<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Services\Api\V2\PredictionWeatherContext;
use App\Services\NFL\GameWeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses()->group('nfl', 'weather');

beforeEach(function () {
    $this->travelTo('2026-09-16 12:00:00');
    config(['nfl.predictions.actual_weather.venue_coordinates' => [
        'test field' => ['latitude' => 40.0, 'longitude' => -75.0, 'timezone' => 'America/New_York'],
    ]]);
});

afterEach(fn () => $this->travelBack());

function reliabilityWeatherGame(array $attributes = []): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => '2026-09-18', 'game_time' => '00:20:00',
        'status' => 'STATUS_SCHEDULED', 'venue_name' => 'Test Field', 'roof' => null,
        ...$attributes,
    ]);
}

it('rejects empty forecasts and measurements outside the kickoff hour', function (array $hourly) {
    Http::fake(['*' => Http::response(['timezone' => 'America/New_York', 'hourly' => $hourly])]);

    expect(app(GameWeatherService::class)->fetch(reliabilityWeatherGame()))->toBeNull();
})->with([
    'empty forecast' => [[]],
    'wrong forecast day' => [['time' => ['2026-09-16T20:00'], 'temperature_2m' => [60], 'wind_speed_10m' => [10], 'precipitation' => [0]]],
    'missing wind' => [['time' => ['2026-09-17T20:00'], 'temperature_2m' => [60], 'precipitation' => [0]]],
]);

it('uses actual neutral venue coordinates before a designated home team', function () {
    config(['nfl.predictions.actual_weather.venue_coordinates' => [
        'NE' => ['latitude' => 42.0, 'longitude' => -71.0],
        'wembley stadium' => ['latitude' => 51.556, 'longitude' => -0.279, 'timezone' => 'Europe/London'],
    ]]);
    $game = reliabilityWeatherGame(['home_team_id' => Team::factory()->create(['abbreviation' => 'NE'])->id, 'venue_name' => 'Wembley Stadium', 'neutral_site' => true]);
    Http::fake(['*' => Http::response(['timezone' => 'Europe/London', 'hourly' => [
        'time' => ['2026-09-18T01:00'], 'temperature_2m' => [60], 'wind_speed_10m' => [10], 'precipitation' => [0],
    ]])]);

    $weather = app(GameWeatherService::class)->fetch($game->load('homeTeam'));

    expect($weather['latitude'])->toBe(51.556)->and($weather['observed_at'])->toBe(Carbon::parse('2026-09-18T00:00:00Z')->setTimezone(config('app.timezone'))->toDateTimeString());
    Http::assertSent(fn (Request $request): bool => $request['latitude'] === 51.556 && $request['timezone'] === 'Europe/London');
});

it('does not infer a closed retractable roof from the venue name', function () {
    $service = app(GameWeatherService::class);
    $game = reliabilityWeatherGame(['venue_name' => 'AT&T Stadium']);

    expect($service->roofStatus($game))->toBe('unknown_retractable');
    $game->roof = 'open';
    expect($service->roofStatus($game))->toBe('open');
    $game->roof = 'closed';
    Http::fake();
    $weather = $service->fetch($game);
    expect($weather['is_indoor'])->toBeTrue()->and($weather['temperature_f'])->toBeNull()->and($weather['provider'])->toBe('venue_metadata');
    Http::assertNothingSent();
});

it('refreshes stale rows by default and preserves failed rows while processing the rest', function () {
    $failedGame = reliabilityWeatherGame();
    $successfulGame = reliabilityWeatherGame();
    $old = GameWeather::create(['game_id' => $failedGame->id, 'provider' => 'open_meteo', 'temperature_f' => 50, 'updated_at' => now()->subDay()]);
    $old->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $oldTime = $old->updated_at;
    $this->mock(GameWeatherService::class, function ($mock) use ($failedGame, $successfulGame) {
        $mock->shouldReceive('fetch')->once()->with(Mockery::on(fn (Game $g) => $g->is($failedGame)))->andThrow(new ConnectionException('timeout'));
        $mock->shouldReceive('fetch')->once()->with(Mockery::on(fn (Game $g) => $g->is($successfulGame)))->andReturn(['provider' => 'venue_metadata', 'is_indoor' => true]);
    });

    $this->artisan('nfl:sync-game-weather', ['--days-back' => 0, '--days-forward' => 7])
        ->expectsOutputToContain('Created 1, updated 0, skipped 0.')
        ->expectsOutputToContain('Failed to refresh 1 NFL weather record(s).')->assertFailed();

    expect($old->fresh()->updated_at->equalTo($oldTime))->toBeTrue();
    $this->assertDatabaseHas('nfl_game_weather', ['game_id' => $successfulGame->id]);
});

it('fails a weather command when the provider returns no usable forecast', function () {
    $game = reliabilityWeatherGame();
    $this->mock(GameWeatherService::class, fn ($mock) => $mock->shouldReceive('fetch')->once()->andReturnNull());

    $this->artisan('nfl:sync-game-weather', ['--game-id' => $game->id])
        ->expectsOutputToContain('"failed_games"')
        ->assertFailed();
    $this->assertDatabaseMissing('nfl_game_weather', ['game_id' => $game->id]);
});

it('does not adjust a forecast using stale weather or an unconfirmed roof', function (array $attributes) {
    $game = reliabilityWeatherGame();
    $weather = (new GameWeather)->forceFill(['temperature_f' => 15, 'wind_speed_mph' => 30, 'precipitation_inches' => 0.1, ...$attributes]);
    $game->setRelation('weather', $weather);
    $model = app(GeneratePredictionFromHistoricalElo::class);
    $method = new ReflectionMethod($model, 'applyActualWeatherBlend');

    expect($method->invoke($model, $game, 3.0, 0.6, 45.0))->toBe([3.0, 0.6, 45.0]);
})->with([
    'stale' => fn () => ['updated_at' => now()->subDay()],
    'unconfirmed roof' => fn () => ['updated_at' => now(), 'raw_payload' => ['provenance' => ['roof_status' => 'unknown_retractable']]],
    'covered open air' => fn () => ['updated_at' => now(), 'raw_payload' => ['provenance' => ['roof_status' => 'covered_open_air']]],
]);

it('applies the cold weather adjustment at subzero temperatures', function () {
    $game = reliabilityWeatherGame();
    $game->setRelation('weather', (new GameWeather)->forceFill(['updated_at' => now(), 'temperature_f' => -5, 'wind_speed_mph' => 0, 'precipitation_inches' => 0]));
    $method = new ReflectionMethod(GeneratePredictionFromHistoricalElo::class, 'applyActualWeatherBlend');

    expect($method->invoke(app(GeneratePredictionFromHistoricalElo::class), $game, 3.0, 0.6, 45.0)[2])->toBe(44.0);
});

it('does not substitute a state climate proxy for unknown field exposure', function (string $venue) {
    $game = reliabilityWeatherGame(['venue_name' => $venue, 'venue_state' => 'TX']);
    $context = (new ReflectionMethod(GeneratePredictionFromHistoricalElo::class, 'weatherTotalContext'))
        ->invoke(app(GeneratePredictionFromHistoricalElo::class), $game);

    expect($context['total_adjustment'])->toBe(0.0)
        ->and($context['reason'])->toBe('unconfirmed_outdoor_exposure');
})->with(['AT&T Stadium', 'SoFi Stadium']);

it('persists the canonical kickoff forecast instant without a database timezone shift', function () {
    config(['app.timezone' => 'America/Chicago']);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026,
        'starts_at' => CarbonImmutable::parse('2026-11-27T01:20:00Z')->setTimezone('America/Chicago')]);
    $game = reliabilityWeatherGame(['sport_event_id' => $event->id, 'game_date' => '2026-11-26', 'game_time' => '20:20:00']);
    Http::fake(['*' => Http::response(['timezone' => 'America/New_York', 'hourly' => [
        'time' => ['2026-11-26T20:00', '2026-11-27T02:00'], 'temperature_2m' => [35, 99],
        'wind_speed_10m' => [10, 99], 'precipitation' => [0.1, 9],
    ]])]);
    $values = app(GameWeatherService::class)->fetch($game);
    $stored = GameWeather::create(['game_id' => $game->id, ...$values])->fresh();
    expect($stored->observed_at->utc()->toIso8601String())->toBe('2026-11-27T01:00:00+00:00')
        ->and((float) $stored->temperature_f)->toBe(35.0);
    $prediction = (new CanonicalPrediction)->setRelation('calculationRun', null);
    $game->setRelation('weather', $stored);
    $context = app(PredictionWeatherContext::class)->forPrediction($prediction, $game);
    expect($context['available'])->toBeTrue()->and($context['source'])->toBe('latest_forecast')
        ->and($context['forecast_valid_at'])->toBe($stored->observed_at->toIso8601String());
});
