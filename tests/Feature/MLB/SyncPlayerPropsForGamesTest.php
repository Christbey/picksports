<?php

use App\Actions\OddsApi\AbstractSyncPlayerPropsForGames;
use App\Actions\OddsApi\MLB\SyncPlayerPropsForGames;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerProp;
use App\Models\MLB\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('mlb', 'odds');

afterEach(function () {
    m::close();
});

it('requests mlb player prop markets and stores matched props', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'San Francisco',
        'name' => 'Giants',
        'abbreviation' => 'SF',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Yankees',
        'abbreviation' => 'NYY',
    ]);
    $player = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'first_name' => 'Logan',
        'last_name' => 'Webb',
        'full_name' => 'Logan Webb',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-07',
        'game_time' => '19:05:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'NYY @ SF',
    ]);

    $event = [
        'id' => 'mlb-odds-event-1',
        'home_team' => 'San Francisco Giants',
        'away_team' => 'New York Yankees',
        'commence_time' => '2026-06-08T00:05:00Z',
    ];

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getOdds')
        ->once()
        ->with('baseball_mlb')
        ->andReturn([$event]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('getPlayerProps')
        ->once()
        ->with('mlb-odds-event-1', 'baseball_mlb', AbstractSyncPlayerPropsForGames::MARKETS_MLB)
        ->andReturn([
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'pitcher_strikeouts',
                    'outcomes' => [
                        ['name' => 'Over', 'description' => 'Logan Webb', 'point' => 5.5, 'price' => -110],
                        ['name' => 'Under', 'description' => 'Logan Webb', 'point' => 5.5, 'price' => -120],
                    ],
                ]],
            ]],
        ]);
    $oddsService->shouldReceive('mappedEspnPlayerId')->once()->andReturn(null);
    $oddsService->shouldReceive('mappedEspnPlayerName')->once()->andReturn(null);

    $stored = (new SyncPlayerPropsForGames($oddsService, app(SportsViewCache::class)))
        ->execute(null, 'baseball_mlb');

    $prop = PlayerProp::query()->first();

    expect($stored)->toBe(1)
        ->and($prop)->not->toBeNull()
        ->and($prop->game_id)->toBe($game->id)
        ->and($prop->player_id)->toBe($player->id)
        ->and($prop->market)->toBe('pitcher_strikeouts')
        ->and((float) $prop->line)->toBe(5.5);
});
