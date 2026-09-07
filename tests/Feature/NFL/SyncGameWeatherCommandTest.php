<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\GameWeatherService;
use Mockery as m;

uses()->group('nfl', 'weather');

afterEach(function () {
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
