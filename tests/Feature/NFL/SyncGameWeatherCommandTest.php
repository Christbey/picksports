<?php

use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Team;
use App\Services\NFL\GameWeatherService;
use Mockery as m;

uses()->group('nfl', 'weather');

afterEach(function () {
    $this->travelBack();
    m::close();
});

it('includes a Sunday night game stored on the next UTC date', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create(['abbreviation' => 'NYG']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'DAL']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-09-14',
        'game_time' => '00:20:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $weatherService = m::mock(GameWeatherService::class);
    $weatherService->shouldReceive('fetch')
        ->once()
        ->with(m::on(fn (Game $candidate): bool => $candidate->is($game)))
        ->andReturn([
            'provider' => 'open_meteo',
            'is_indoor' => false,
            'location_source' => 'geocoded_venue_city',
            'observed_at' => '2026-09-14 00:00:00',
        ]);
    $this->app->instance(GameWeatherService::class, $weatherService);

    $this->artisan('nfl:sync-game-weather', [
        '--season' => 2026,
        '--days-back' => 0,
        '--days-forward' => 7,
        '--force' => true,
    ])
        ->expectsOutput('NFL weather sync complete. Created 1, updated 0, skipped 0.')
        ->assertExitCode(0);

    $this->assertDatabaseHas('nfl_game_weather', [
        'game_id' => $game->id,
        'provider' => 'open_meteo',
    ]);
});

it('marks an unchanged indoor forecast fresh after a forced refresh', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create(['abbreviation' => 'DET']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'NO']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-09-13',
        'game_time' => '17:00:00',
        'status' => 'STATUS_SCHEDULED',
    ]);
    $forecast = [
        'provider' => 'open_meteo',
        'is_indoor' => true,
        'location_source' => 'indoor_venue',
        'observed_at' => '2026-09-13 17:00:00',
    ];
    $weather = GameWeather::query()->create(['game_id' => $game->id, ...$forecast]);
    $weather->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

    $weatherService = m::mock(GameWeatherService::class);
    $weatherService->shouldReceive('fetch')
        ->once()
        ->with(m::on(fn (Game $candidate): bool => $candidate->is($game)))
        ->andReturn($forecast);
    $this->app->instance(GameWeatherService::class, $weatherService);

    $this->artisan('nfl:sync-game-weather', [
        '--game-id' => $game->id,
        '--force' => true,
    ])
        ->expectsOutput('NFL weather sync complete. Created 0, updated 1, skipped 0.')
        ->assertExitCode(0);

    expect($weather->fresh()->updated_at->equalTo(now()))->toBeTrue();
});
