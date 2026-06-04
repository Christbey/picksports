<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Team as WnbaTeam;
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

it('returns a clean json 404 for unsupported v2 sport game endpoints', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nhl/games')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/nhl/games/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
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
