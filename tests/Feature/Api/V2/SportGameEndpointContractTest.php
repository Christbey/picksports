<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\SportEvent;
use App\Models\User;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\MLB\MlbStartingPitcherForecastService;
use Laravel\Sanctum\Sanctum;

dataset('v2GameContractSports', [
    'nba' => ['nba', NbaTeam::class, NbaGame::class],
    'nfl' => ['nfl', NflTeam::class, NflGame::class],
    'mlb' => ['mlb', MlbTeam::class, MlbGame::class],
    'cbb' => ['cbb', CbbTeam::class, CbbGame::class],
    'cfb' => ['cfb', CfbTeam::class, CfbGame::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbGame::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaGame::class],
]);

it('requires authenticated access for v2 game endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/games")
        ->assertUnauthorized();

    $this->getJson("/api/v2/sports/{$slug}/games/1")
        ->assertUnauthorized();
})->with('v2GameContractSports');

it('shows v2 mlb games with page-ready matchup context and probable pitcher fields', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = MlbTeam::factory()->create([
        'abbreviation' => 'SF',
        'name' => 'Giants',
        'division' => 'West',
    ]);
    $awayTeam = MlbTeam::factory()->create([
        'abbreviation' => 'NYY',
        'name' => 'Yankees',
        'division' => 'East',
    ]);
    $homePitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '5001',
        'full_name' => 'Logan Webb',
    ]);
    $awayPitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '5002',
        'full_name' => 'Gerrit Cole',
    ]);

    MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
        'game_time' => '18:05:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 2,
        'away_score' => 4,
    ]);

    $game = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'game_time' => '19:05:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id,
        'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
        'venue_name' => 'Oracle Park',
        'venue_city' => 'San Francisco',
        'venue_state' => 'CA',
        'home_linescores' => [['period' => 1, 'value' => 0]],
        'away_linescores' => [['period' => 1, 'value' => 0]],
        'broadcast_networks' => ['ESPN'],
    ]);

    $response = $this->getJson("/api/v2/sports/mlb/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('meta.sport', 'mlb')
        ->assertJsonPath('data.venue_name', 'Oracle Park')
        ->assertJsonPath('data.venue_city', 'San Francisco')
        ->assertJsonPath('data.probable_home_pitcher_espn_id', '5001')
        ->assertJsonPath('data.probable_away_pitcher_espn_id', '5002')
        ->assertJsonPath('data.home_starting_pitcher.full_name', 'Logan Webb')
        ->assertJsonPath('data.away_starting_pitcher.full_name', 'Gerrit Cole')
        ->assertJsonPath('data.home_team.abbreviation', 'SF')
        ->assertJsonPath('data.away_team.abbreviation', 'NYY')
        ->assertJsonStructure([
            'data' => [
                'home_linescores',
                'away_linescores',
                'broadcast_networks',
                'matchup_context' => [
                    'rows',
                ],
            ],
        ]);

    expect(collect($response->json('data.matchup_context.rows'))->pluck('key')->all())
        ->toContain('head_to_head');
});

it('returns a clean json 404 for unsupported v2 sport game endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/games')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/games/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});

it('resolves games by canonical sport event ulid without changing the legacy numeric id', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = NbaTeam::factory()->create();
    $awayTeam = NbaTeam::factory()->create();
    $sportEvent = SportEvent::factory()->create(['sport' => 'nba']);
    $game = NbaGame::factory()->create([
        'sport_event_id' => $sportEvent->id,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $this->getJson("/api/v2/sports/nba/games/{$sportEvent->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $game->id)
        ->assertJsonPath('data.sport_event_id', $sportEvent->public_id)
        ->assertJsonPath('data.sport', 'nba');

    $this->getJson("/api/v2/sports/mlb/games/{$sportEvent->public_id}")
        ->assertNotFound();
});

it('returns a projected mlb starter with explicit rotation provenance', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = MlbTeam::factory()->create(['abbreviation' => 'CLE']);
    $awayTeam = MlbTeam::factory()->create(['abbreviation' => 'ARI']);
    $projectedPitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => 'projected-5001',
        'full_name' => 'Projected Starter',
        'elo_rating' => 1512,
    ]);
    $game = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => null,
        'projected_home_pitcher_espn_id' => $projectedPitcher->espn_id,
        'projected_home_pitcher_confidence' => 0.64,
        'pitcher_projection_metadata' => [
            'version' => 'rotation-v2',
            'home' => [
                'status' => 'projected',
                'expected_pitcher_rating' => 1508.5,
                'uncertainty' => 0.4,
                'candidates' => [[
                    'pitcher_espn_id' => $projectedPitcher->espn_id,
                    'pitcher_name' => $projectedPitcher->full_name,
                    'rating' => 1512,
                    'probability' => 0.6,
                ]],
            ],
        ],
        'pitcher_projection_generated_at' => now(),
    ]);

    $this->getJson("/api/v2/sports/mlb/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.home_starting_pitcher.full_name', 'Projected Starter')
        ->assertJsonPath('data.home_starting_pitcher_source', 'rotation_projection')
        ->assertJsonPath('data.home_starting_pitcher_confidence', 0.64)
        ->assertJsonPath('data.home_starting_pitcher_candidates.0.pitcher_espn_id', 'projected-5001')
        ->assertJsonPath('data.home_starting_pitcher_candidates.0.probability', 0.6)
        ->assertJsonPath('data.home_expected_starting_pitcher_rating', 1508.5)
        ->assertJsonPath('data.home_starting_pitcher_uncertainty', 0.4)
        ->assertJsonPath('data.probable_home_pitcher_espn_id', null)
        ->assertJsonPath('data.projected_home_pitcher_espn_id', 'projected-5001');
});

it('returns the box score starter ahead of probable and projected mlb pitchers', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = MlbTeam::factory()->create(['abbreviation' => 'CLE']);
    $awayTeam = MlbTeam::factory()->create(['abbreviation' => 'ARI']);
    $actualPitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => 'actual-5001',
        'full_name' => 'Confirmed Starter',
    ]);
    $actualAwayPitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => 'actual-away-5001',
        'full_name' => 'Confirmed Away Starter',
    ]);
    $game = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'actual_home_pitcher_espn_id' => $actualPitcher->espn_id,
        'actual_away_pitcher_espn_id' => $actualAwayPitcher->espn_id,
        'probable_home_pitcher_espn_id' => 'probable-5001',
        'projected_home_pitcher_espn_id' => 'projected-5001',
        'starting_pitcher_confirmation_metadata' => ['home' => ['source' => 'espn_boxscore']],
        'starting_pitchers_confirmed_at' => now(),
    ]);
    app(MlbStartingPitcherForecastService::class)->record($game, 'home', [
        'pitcher_espn_id' => $actualPitcher->espn_id,
        'confidence' => 0.82,
        'evidence' => ['rotation_size' => 5],
    ]);
    app(MlbStartingPitcherForecastService::class)->record($game, 'away', [
        'pitcher_espn_id' => $actualAwayPitcher->espn_id,
        'confidence' => 0.74,
        'evidence' => ['rotation_size' => 5],
    ]);

    $this->getJson("/api/v2/sports/mlb/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.home_starting_pitcher.full_name', 'Confirmed Starter')
        ->assertJsonPath('data.home_starting_pitcher_source', 'espn_boxscore_confirmed')
        ->assertJsonPath('data.home_starting_pitcher_confidence', 1)
        ->assertJsonPath('data.actual_home_pitcher_espn_id', 'actual-5001')
        ->assertJsonPath('data.starting_pitcher_confirmation_metadata.home.source', 'espn_boxscore')
        ->assertJsonPath('data.home_starting_pitcher_forecast.predicted_pitcher.full_name', 'Confirmed Starter')
        ->assertJsonPath('data.home_starting_pitcher_forecast.grade', 'correct')
        ->assertJsonPath('data.home_starting_pitcher_forecast.confidence', 0.82)
        ->assertJsonPath('data.away_starting_pitcher_forecast.predicted_pitcher.full_name', 'Confirmed Away Starter')
        ->assertJsonPath('data.away_starting_pitcher_forecast.grade', 'correct')
        ->assertJsonPath('data.away_starting_pitcher_forecast.confidence', 0.74);
});

it('presents cfb v2 game dates in eastern football time', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = CfbTeam::factory()->create(['abbreviation' => 'UNLV']);
    $awayTeam = CfbTeam::factory()->create(['abbreviation' => 'MEM']);
    $game = CfbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 1,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-08-30 00:00:00',
        'game_time' => '02:00:00',
    ]);

    $this->getJson("/api/v2/sports/cfb/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.game_date', '2026-08-29')
        ->assertJsonPath('data.game_time', '22:00:00');
});

it('treats late-night wnba utc starts as the local game date for games', function () {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = WnbaTeam::factory()->create(['abbreviation' => 'POR']);
    $awayTeam = WnbaTeam::factory()->create(['abbreviation' => 'IND']);
    $game = WnbaGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-08-01 00:00:00',
        'game_time' => '02:00:00',
    ]);

    WnbaGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-08-01 00:00:00',
        'game_time' => '20:00:00',
    ]);

    $priorEveningGame = WnbaGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-07-31 00:00:00',
        'game_time' => '00:00:00',
    ]);

    $this->getJson("/api/v2/sports/wnba/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.game_date', '2026-07-31')
        ->assertJsonPath('data.game_time', '22:00:00');

    $response = $this->getJson('/api/v2/sports/wnba/games?season=2026&from_date=2026-07-31&to_date=2026-07-31&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($game->id)
        ->not->toContain($priorEveningGame->id);
});

it('lists v2 games with sport, filter, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
) {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/games?season=2026&status=STATUS_SCHEDULED&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sport_event_id',
                    'espn_id',
                    'home_team_id',
                    'away_team_id',
                    'season',
                    'season_type',
                    'game_date',
                    'status',
                    'home_team',
                    'away_team',
                ],
            ],
            'meta' => [
                'sport',
                'filters',
                'pagination',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.status', 'STATUS_SCHEDULED')
        ->assertJsonPath('data.0.id', $game->id)
        ->assertJsonPath('data.0.home_team_id', $homeTeam->id)
        ->assertJsonPath('data.0.away_team_id', $awayTeam->id);

    expect($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2GameContractSports');

it('shows a v2 game with sport, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
) {
    Sanctum::actingAs(User::factory()->create());

    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/games/{$game->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'sport_event_id',
                'espn_id',
                'home_team_id',
                'away_team_id',
                'season',
                'season_type',
                'game_date',
                'status',
                'home_team',
                'away_team',
            ],
            'meta' => [
                'sport',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('data.id', $game->id)
        ->assertJsonPath('data.home_team_id', $homeTeam->id)
        ->assertJsonPath('data.away_team_id', $awayTeam->id);

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2GameContractSports');

it('lists v2 games for a team with sport, team, filters, pagination, freshness, and warning metadata', function (
    string $slug,
    string $teamModel,
    string $gameModel,
) {
    Sanctum::actingAs(User::factory()->create());

    $targetTeam = $teamModel::factory()->create();
    $opponent = $teamModel::factory()->create();
    $otherHomeTeam = $teamModel::factory()->create();
    $otherAwayTeam = $teamModel::factory()->create();

    $targetGame = $gameModel::factory()->create([
        'home_team_id' => $targetTeam->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-01 18:00:00',
    ]);
    $gameModel::factory()->create([
        'home_team_id' => $otherHomeTeam->id,
        'away_team_id' => $otherAwayTeam->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-02 18:00:00',
    ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/teams/{$targetTeam->id}/games?season=2026&status=STATUS_FINAL&per_page=5")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'home_team_id',
                    'away_team_id',
                    'season',
                    'status',
                    'home_team',
                    'away_team',
                ],
            ],
            'meta' => [
                'sport',
                'team_id',
                'filters',
                'pagination',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.team_id', $targetTeam->id)
        ->assertJsonPath('meta.filters.team_id', $targetTeam->id)
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('data.0.id', $targetGame->id)
        ->assertJsonPath('data.0.home_team_id', $targetTeam->id);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.pagination'))->toBeArray()
        ->and($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();
})->with('v2GameContractSports');
