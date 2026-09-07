<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Mockery as m;

uses()->group('nfl', 'predictions');

afterEach(function () {
    $this->travelBack();
    m::close();
});

it('includes Sunday night games stored on the next UTC date in a local date window', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create(['abbreviation' => 'NYG']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'DAL']);
    $sundayNight = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-09-14',
        'game_time' => '00:20:00',
        'status' => 'STATUS_SCHEDULED',
    ]);
    Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
        'game_date' => '2026-09-15',
        'game_time' => '00:15:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')
        ->once()
        ->with(m::on(fn (Game $game): bool => $game->is($sundayNight)))
        ->andReturn('updated');
    $this->app->instance(GeneratePredictionFromHistoricalElo::class, $generator);

    $this->artisan('nfl:generate-predictions', [
        '--season' => 2026,
        '--from-date' => '2026-09-06',
        '--to-date' => '2026-09-13',
    ])
        ->expectsOutput('Generating predictions for 1 games...')
        ->expectsOutput('Prediction generation complete! 0 created, 1 updated.')
        ->assertExitCode(0);
});
