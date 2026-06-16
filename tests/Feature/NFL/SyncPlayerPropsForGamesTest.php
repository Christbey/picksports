<?php

use App\Actions\OddsApi\AbstractSyncPlayerPropsForGames;
use App\Actions\OddsApi\NFL\SyncPlayerPropsForGames;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('nfl', 'odds');

afterEach(function () {
    m::close();
});

it('requests nfl football player prop markets and stores matched props', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Chiefs',
        'abbreviation' => 'KC',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Raiders',
        'abbreviation' => 'LV',
    ]);
    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'first_name' => 'Patrick',
        'last_name' => 'Mahomes',
        'full_name' => 'Patrick Mahomes',
        'position' => 'QB',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('nfl.season.types.regular', 2),
        'game_date' => '2026-09-13',
        'game_time' => '19:20:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'LV @ KC',
    ]);

    $event = [
        'id' => 'nfl-odds-event-1',
        'home_team' => 'Kansas City Chiefs',
        'away_team' => 'Las Vegas Raiders',
        'commence_time' => '2026-09-14T00:20:00Z',
    ];

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getOdds')
        ->once()
        ->with('americanfootball_nfl')
        ->andReturn([$event]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('getPlayerProps')
        ->once()
        ->with('nfl-odds-event-1', 'americanfootball_nfl', AbstractSyncPlayerPropsForGames::MARKETS_NFL)
        ->andReturn([
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'player_pass_yds',
                    'outcomes' => [
                        ['name' => 'Over', 'description' => 'Patrick Mahomes', 'point' => 250.5, 'price' => -110],
                        ['name' => 'Under', 'description' => 'Patrick Mahomes', 'point' => 250.5, 'price' => -110],
                    ],
                ]],
            ]],
        ]);
    $oddsService->shouldReceive('mappedEspnPlayerId')->once()->andReturn(null);
    $oddsService->shouldReceive('mappedEspnPlayerName')->once()->andReturn(null);

    $stored = (new SyncPlayerPropsForGames($oddsService, app(SportsViewCache::class)))
        ->execute(null, 'americanfootball_nfl');

    $prop = PlayerProp::query()->first();

    expect($stored)->toBe(1)
        ->and($prop)->not->toBeNull()
        ->and($prop->game_id)->toBe($game->id)
        ->and($prop->player_id)->toBe($player->id)
        ->and($prop->market)->toBe('player_pass_yds')
        ->and((float) $prop->line)->toBe(250.5);
});
