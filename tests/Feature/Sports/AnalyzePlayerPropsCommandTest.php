<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

it('can analyze only active games missing recommendation-ready prop outputs', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $readyGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);
    $missingGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);

    DB::table('nba_player_props')->insert([
        [
            'game_id' => $readyGame->id,
            'player_name' => 'Ready Prop',
            'market' => 'player_points',
            'bookmaker' => 'draftkings',
            'line' => 20.5,
            'over_price' => -110,
            'under_price' => -110,
            'recommended_side' => 'Over',
            'confidence_score' => 72,
            'predicted_over_probability' => 61.2,
            'market_over_probability' => 52.4,
            'edge_probability' => 8.8,
            'data_quality_score' => 90,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'game_id' => $missingGame->id,
            'player_name' => 'Unscored Prop',
            'market' => 'player_points',
            'bookmaker' => 'draftkings',
            'line' => 18.5,
            'over_price' => -110,
            'under_price' => -110,
            'recommended_side' => null,
            'confidence_score' => null,
            'predicted_over_probability' => null,
            'market_over_probability' => null,
            'edge_probability' => null,
            'data_quality_score' => null,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $analyzer = m::mock(PlayerPropAnalyzer::class);
    $analyzer->shouldReceive('analyzeProps')
        ->once()
        ->with('NBA', 3, null, $missingGame->id)
        ->andReturn(new Collection([['recommendation' => 'Under']]));

    $this->app->instance(PlayerPropAnalyzer::class, $analyzer);

    $this->artisan('sports:analyze-player-props', [
        '--sport' => 'nba',
        '--season' => 2026,
        '--only-missing' => true,
    ])
        ->expectsOutput('Only-missing mode: 1/2 active game(s) need recommendation analysis.')
        ->expectsOutput('Analyzing NBA player props for 1 active game(s) in finals stage.')
        ->expectsOutput("Game {$missingGame->id}: 1 recommendation(s).")
        ->expectsOutput('Player prop analysis complete. 1 recommendation(s) persisted.')
        ->assertExitCode(0);
});
