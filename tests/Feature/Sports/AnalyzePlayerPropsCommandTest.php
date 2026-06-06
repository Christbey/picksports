<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Support\Collection;
use Mockery as m;

uses()->group('sports');

afterEach(function () {
    m::close();
});

it('analyzes player props for active stage games', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $analyzer = m::mock(PlayerPropAnalyzer::class);
    $analyzer->shouldReceive('analyzeProps')
        ->once()
        ->with('NBA', 3, null, $game->id)
        ->andReturn(new Collection([['recommendation' => 'Over']]));

    $this->app->instance(PlayerPropAnalyzer::class, $analyzer);

    $this->artisan('sports:analyze-player-props', [
        '--sport' => 'nba',
        '--season' => 2026,
    ])
        ->expectsOutput('Analyzing NBA player props for 1 active game(s) in finals stage.')
        ->expectsOutput("Game {$game->id}: 1 recommendation(s).")
        ->expectsOutput('Player prop analysis complete. 1 recommendation(s) persisted.')
        ->assertExitCode(0);
});
