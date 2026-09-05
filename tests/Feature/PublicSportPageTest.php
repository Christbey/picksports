<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Inertia\Testing\AssertableInertia as Assert;

test('nba public sport page renders public summary data', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);

    $awayTeam = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Lakers',
        'abbreviation' => 'LAL',
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'wins' => 51,
        'losses' => 18,
        'offensive_efficiency' => 118.4,
        'defensive_efficiency' => 109.1,
        'net_rating' => 9.3,
        'tempo' => 98.1,
    ]);

    PlayoffForecast::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'conference' => 'Eastern',
        'conference_rank' => 1,
        'projected_seed' => 1,
        'selection_score' => 0.91,
        'playoff_make_probability' => 0.99,
        'direct_playoff_probability' => 0.97,
        'play_in_tournament_probability' => 0.01,
        'division_win_probability' => 0.72,
        'conference_finals_probability' => 0.41,
        'nba_finals_probability' => 0.28,
        'champion_probability' => 0.16,
        'simulation_runs' => 500,
    ]);

    PlayoffForecast::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'conference' => 'Western',
        'conference_rank' => 2,
        'projected_seed' => 2,
        'selection_score' => 0.84,
        'playoff_make_probability' => 0.96,
        'direct_playoff_probability' => 0.89,
        'play_in_tournament_probability' => 0.07,
        'division_win_probability' => 0.44,
        'conference_finals_probability' => 0.27,
        'nba_finals_probability' => 0.18,
        'champion_probability' => 0.09,
        'simulation_runs' => 500,
    ]);

    $player = Player::factory()->for($homeTeam)->create([
        'first_name' => 'Jayson',
        'last_name' => 'Tatum',
        'full_name' => 'Jayson Tatum',
        'position' => 'F',
    ]);

    $games = Game::factory()->count(5)->create([
        'season' => 2026,
        'status' => config('nba.statuses.final'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    foreach ($games as $game) {
        PlayerStat::factory()->create([
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'game_id' => $game->id,
            'minutes_played' => '34:00',
            'points' => 28,
            'rebounds_total' => 8,
            'assists' => 6,
            'turnovers' => 2,
            'steals' => 1,
            'blocks' => 1,
            'field_goals_made' => 10,
            'field_goals_attempted' => 18,
            'free_throws_made' => 6,
            'free_throws_attempted' => 7,
        ]);
    }

    $upcomingGame = Game::factory()->create([
        'season' => 2026,
        'status' => config('nba.statuses.scheduled'),
        'game_date' => now()->addDay(),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    Prediction::query()->create([
        'game_id' => $upcomingGame->id,
        'home_elo' => 1612.5,
        'away_elo' => 1560.2,
        'home_off_eff' => 118.4,
        'home_def_eff' => 109.1,
        'away_off_eff' => 112.0,
        'away_def_eff' => 111.9,
        'predicted_spread' => -6.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.67,
        'confidence_score' => 74.2,
    ]);

    PlayerInjury::query()->create([
        'player_id' => $player->id,
        'team_id' => $homeTeam->id,
        'injury_key' => 'ankle-sprain',
        'status' => 'Out',
        'detail' => 'Ankle sprain',
        'type' => 'Ankle',
        'source_updated_at' => now(),
        'is_active' => true,
    ]);

    $this->withoutVite();

    $response = $this->get('/nba');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicSport')
        ->where('sport', 'nba')
        ->where('sportLabel', 'NBA')
        ->has('conferencePlayoffTeams.east', 1)
        ->has('conferencePlayoffTeams.west', 1)
        ->has('injuries.top', 1)
        ->has('injuries.recent', 1)
        ->has('topTeams', 1)
        ->has('topPlayers', 1)
        ->has('featuredPredictions', 1)
    );
});

test('public top players section caps repeated teams', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);

    $awayTeam = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Lakers',
        'abbreviation' => 'LAL',
    ]);

    $games = Game::factory()->count(5)->create([
        'season' => 2026,
        'status' => config('nba.statuses.final'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $bostonPlayers = collect([
        ['name' => 'Alpha One', 'points' => 31],
        ['name' => 'Beta Two', 'points' => 29],
        ['name' => 'Gamma Three', 'points' => 27],
    ])->map(function (array $playerData) use ($homeTeam, $games) {
        $player = Player::factory()->for($homeTeam)->create([
            'full_name' => $playerData['name'],
            'first_name' => explode(' ', $playerData['name'])[0],
            'last_name' => explode(' ', $playerData['name'])[1],
        ]);

        foreach ($games as $game) {
            PlayerStat::factory()->create([
                'player_id' => $player->id,
                'team_id' => $homeTeam->id,
                'game_id' => $game->id,
                'minutes_played' => '35:00',
                'points' => $playerData['points'],
                'rebounds_total' => 6,
                'assists' => 5,
                'turnovers' => 2,
                'steals' => 1,
                'blocks' => 1,
                'field_goals_made' => 11,
                'field_goals_attempted' => 19,
                'free_throws_made' => 5,
                'free_throws_attempted' => 6,
            ]);
        }

        return $player;
    });

    $lakersPlayer = Player::factory()->for($awayTeam)->create([
        'full_name' => 'Delta Four',
        'first_name' => 'Delta',
        'last_name' => 'Four',
    ]);

    foreach ($games as $game) {
        PlayerStat::factory()->create([
            'player_id' => $lakersPlayer->id,
            'team_id' => $awayTeam->id,
            'game_id' => $game->id,
            'minutes_played' => '35:00',
            'points' => 26,
            'rebounds_total' => 7,
            'assists' => 7,
            'turnovers' => 2,
            'steals' => 1,
            'blocks' => 0,
            'field_goals_made' => 10,
            'field_goals_attempted' => 18,
            'free_throws_made' => 4,
            'free_throws_attempted' => 5,
        ]);
    }

    $this->withoutVite();

    $response = $this->get('/nba');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicSport')
        ->where('topPlayers', fn ($players) => collect($players)
            ->where('team_abbreviation', 'BOS')
            ->count() <= 2
        )
    );
});

test('public sport pages advertise only configured destinations', function (string $sport, array $links, bool $hasPlayerProps) {
    $this->withoutVite();

    $response = $this->get("/{$sport}");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicSport')
        ->where('sport', $sport)
        ->where('hasPlayerProps', $hasPlayerProps)
        ->where('links', $links)
    );
})->with([
    'cfb omits unavailable team metrics and props' => [
        'cfb',
        [
            'predictions' => '/cfb/predictions',
            'injuries' => '/cfb/injuries',
            'teamMetrics' => null,
            'playerStats' => '/cfb/player-stats',
            'playerProps' => null,
        ],
        false,
    ],
    'wcbb omits unavailable player pages and props' => [
        'wcbb',
        [
            'predictions' => '/wcbb/predictions',
            'injuries' => '/wcbb/injuries',
            'teamMetrics' => '/wcbb/team-metrics',
            'playerStats' => null,
            'playerProps' => null,
        ],
        false,
    ],
    'wnba exposes props but omits unavailable player stats' => [
        'wnba',
        [
            'predictions' => '/wnba/predictions',
            'injuries' => '/wnba/injuries',
            'teamMetrics' => '/wnba/team-metrics',
            'playerStats' => null,
            'playerProps' => '/wnba/player-props',
        ],
        true,
    ],
]);

test('public player prop summaries do not generate narratives during page loads', function () {
    $analyzer = Mockery::mock(PlayerPropAnalyzer::class);
    $analyzer->shouldReceive('getAvailableDatesForSport')
        ->once()
        ->with('WNBA')
        ->andReturn(collect());
    $analyzer->shouldReceive('analyzeProps')
        ->once()
        ->with('WNBA', 3, null, null, null, false)
        ->andReturn(collect());

    app()->instance(PlayerPropAnalyzer::class, $analyzer);
    $this->withoutVite();

    $this->get('/wnba')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PublicSport')
            ->where('summary.propsCount', 0)
        );
});
