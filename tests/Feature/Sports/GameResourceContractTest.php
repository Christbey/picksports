<?php

use App\Http\Resources\MLB\GameResource as MlbGameResource;
use App\Http\Resources\NBA\GameResource as NbaGameResource;
use App\Http\Resources\NFL\GameResource as NflGameResource;
use App\Http\Resources\WNBA\GameResource as WnbaGameResource;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Team as WnbaTeam;
use Carbon\Carbon;
use Illuminate\Http\Request;

function gameResourceRequest(): Request
{
    return Request::create('/');
}

test('nfl game resource exposes stable venue and clock aliases with nested teams', function () {
    $game = (new NflGame)->forceFill([
        'id' => 10,
        'home_team_id' => 1,
        'away_team_id' => 2,
        'season' => 2025,
        'season_type' => 'Regular Season',
        'week' => 3,
        'game_date' => '2025-09-21',
        'game_time' => '19:20:00',
        'venue_name' => 'Arrowhead Stadium',
        'game_clock' => '05:33',
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
        'home_score' => 24,
        'away_score' => 20,
    ])
        ->setRelation('homeTeam', new NflTeam(['id' => 1, 'abbreviation' => 'KC', 'name' => 'Chiefs', 'location' => 'Kansas City']))
        ->setRelation('awayTeam', new NflTeam(['id' => 2, 'abbreviation' => 'BUF', 'name' => 'Bills', 'location' => 'Buffalo']));

    $data = NflGameResource::make($game)->response()->getData(true)['data'];

    expect($data)->toMatchArray([
        'venue' => 'Arrowhead Stadium',
        'venue_name' => 'Arrowhead Stadium',
        'clock' => '05:33',
        'game_clock' => '05:33',
    ]);
    expect(data_get($data, 'home_team.abbreviation'))->toBe('KC')
        ->and(data_get($data, 'away_team.abbreviation'))->toBe('BUF');
});

test('nba game resource includes legacy and canonical aliases together', function () {
    $game = (new NbaGame)->forceFill([
        'id' => 21,
        'home_team_id' => 3,
        'away_team_id' => 4,
        'season' => 2025,
        'season_type' => 'Regular Season',
        'game_date' => Carbon::parse('2025-12-25'),
        'game_time' => '19:00:00',
        'venue_name' => 'Madison Square Garden',
        'venue_city' => 'New York',
        'game_clock' => '02:14',
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 4,
    ])
        ->setRelation('homeTeam', new NbaTeam(['id' => 3, 'abbreviation' => 'NYK', 'location' => 'New York', 'name' => 'Knicks']))
        ->setRelation('awayTeam', new NbaTeam(['id' => 4, 'abbreviation' => 'BOS', 'location' => 'Boston', 'name' => 'Celtics']));

    $data = NbaGameResource::make($game)->response()->getData(true)['data'];

    expect($data)->toMatchArray([
        'venue' => 'Madison Square Garden',
        'venue_name' => 'Madison Square Garden',
        'clock' => '02:14',
        'game_clock' => '02:14',
    ]);
    expect(data_get($data, 'home_team.abbreviation'))->toBe('NYK')
        ->and(data_get($data, 'away_team.abbreviation'))->toBe('BOS');
});

test('wnba game resource exposes game time plus venue and clock aliases', function () {
    $game = (new WnbaGame)->forceFill([
        'id' => 9,
        'home_team_id' => 8,
        'away_team_id' => 7,
        'season' => 2025,
        'season_type' => 'Regular Season',
        'game_date' => Carbon::parse('2025-08-10 19:00:00'),
        'game_time' => '19:00:00',
        'venue_name' => 'Michelob ULTRA Arena',
        'game_clock' => '08:44',
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
    ])
        ->setRelation('homeTeam', new WnbaTeam(['id' => 8, 'abbreviation' => 'LVA', 'location' => 'Las Vegas', 'name' => 'Aces']))
        ->setRelation('awayTeam', new WnbaTeam(['id' => 7, 'abbreviation' => 'SEA', 'location' => 'Seattle', 'name' => 'Storm']));

    $data = WnbaGameResource::make($game)->response()->getData(true)['data'];

    expect($data)->toMatchArray([
        'game_time' => '19:00:00',
        'venue' => 'Michelob ULTRA Arena',
        'venue_name' => 'Michelob ULTRA Arena',
        'clock' => '08:44',
        'game_clock' => '08:44',
    ]);
});

test('mlb game resource includes shared venue alias', function () {
    $game = (new MlbGame)->forceFill([
        'id' => 30,
        'home_team_id' => 1,
        'away_team_id' => 2,
        'season' => 2025,
        'game_date' => Carbon::parse('2025-06-01'),
        'game_time' => '13:10:00',
        'venue_name' => 'Wrigley Field',
        'status' => 'STATUS_SCHEDULED',
    ])
        ->setRelation('homeTeam', new MlbTeam(['id' => 1, 'abbreviation' => 'CHC', 'location' => 'Chicago', 'name' => 'Cubs']))
        ->setRelation('awayTeam', new MlbTeam(['id' => 2, 'abbreviation' => 'STL', 'location' => 'St. Louis', 'name' => 'Cardinals']));

    $data = MlbGameResource::make($game)->response()->getData(true)['data'];

    expect($data)->toMatchArray([
        'venue' => 'Wrigley Field',
        'venue_name' => 'Wrigley Field',
    ]);
    expect(data_get($data, 'home_team.abbreviation'))->toBe('CHC')
        ->and(data_get($data, 'away_team.abbreviation'))->toBe('STL');
});
