<?php

use App\Actions\OddsApi\NBA\SyncPlayerPropsForGames;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('nba', 'odds');

afterEach(function () {
    m::close();
});

it('matches postseason player props when odds api commence time falls on next utc date', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Cleveland',
        'name' => 'Cavaliers',
        'abbreviation' => 'CLE',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Knicks',
        'abbreviation' => 'NY',
    ]);
    $player = Player::factory()->create([
        'team_id' => $awayTeam->id,
        'first_name' => 'Jalen',
        'last_name' => 'Brunson',
        'full_name' => 'Jalen Brunson',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 3,
        'game_date' => '2026-05-23',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'NY @ CLE',
    ]);

    $event = [
        'id' => 'odds-event-1',
        'home_team' => 'Cleveland Cavaliers',
        'away_team' => 'New York Knicks',
        'commence_time' => '2026-05-24T00:10:00Z',
    ];

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getOdds')
        ->once()
        ->with('basketball_nba')
        ->andReturn([$event]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('getPlayerProps')
        ->once()
        ->with('odds-event-1', 'basketball_nba', ['player_points'])
        ->andReturn([
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'markets' => [
                        [
                            'key' => 'player_points',
                            'outcomes' => [
                                [
                                    'name' => 'Over',
                                    'description' => 'Jalen Brunson',
                                    'point' => 28.5,
                                    'price' => -110,
                                ],
                                [
                                    'name' => 'Under',
                                    'description' => 'Jalen Brunson',
                                    'point' => 28.5,
                                    'price' => -120,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    $oddsService->shouldReceive('mappedEspnPlayerId')->once()->andReturn(null);
    $oddsService->shouldReceive('mappedEspnPlayerName')->once()->andReturn(null);

    $stored = (new SyncPlayerPropsForGames($oddsService, app(SportsViewCache::class)))
        ->execute(['player_points'], 'basketball_nba');

    $prop = PlayerProp::query()->first();

    expect($stored)->toBe(1)
        ->and($prop)->not->toBeNull()
        ->and($prop->game_id)->toBe($game->id)
        ->and($prop->player_id)->toBe($player->id)
        ->and($prop->market)->toBe('player_points')
        ->and((float) $prop->line)->toBe(28.5)
        ->and($prop->over_price)->toBe(-110)
        ->and($prop->under_price)->toBe(-120);
});
