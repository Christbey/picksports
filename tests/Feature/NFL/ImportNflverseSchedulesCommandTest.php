<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

uses()->group('nfl');

it('imports nflverse schedules with closing lines weather coaches and quarterbacks', function () {
    Team::factory()->create([
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Chiefs',
    ]);
    Team::factory()->create([
        'abbreviation' => 'DEN',
        'location' => 'Denver',
        'name' => 'Broncos',
    ]);

    $path = sys_get_temp_dir().'/nflverse-schedules-test.csv';
    File::put($path, implode("\n", [
        'game_id,season,game_type,week,gameday,gametime,away_team,home_team,away_score,home_score,spread_line,total_line,away_spread_odds,home_spread_odds,over_odds,under_odds,away_qb_id,away_qb_name,home_qb_id,home_qb_name,away_coach,home_coach,stadium,stadium_id,roof,surface,temp,wind,away_rest,home_rest,div_game,location',
        '2026_01_DEN_KC,2026,REG,1,2026-09-13,20:20,DEN,KC,17,24,-2.5,44.5,-110,-108,-112,-108,00-0031234,Bo Nix,00-0033873,Patrick Mahomes,Sean Payton,Andy Reid,GEHA Field at Arrowhead Stadium,KAN00,outdoors,grass,72,8,7,7,1,Home',
    ]));

    artisan('nfl:import-nflverse-schedules', [
        'file' => $path,
        '--from-season' => 2026,
        '--to-season' => 2026,
    ])->assertExitCode(0);

    $game = Game::query()->where('nflverse_game_id', '2026_01_DEN_KC')->firstOrFail();

    expect($game->short_name)->toBe('DEN @ KC')
        ->and($game->season_type)->toBe('2')
        ->and($game->home_score)->toBe(24)
        ->and($game->away_score)->toBe(17)
        ->and($game->home_qb_name)->toBe('Patrick Mahomes')
        ->and($game->away_qb_name)->toBe('Bo Nix')
        ->and($game->home_coach)->toBe('Andy Reid')
        ->and($game->away_coach)->toBe('Sean Payton')
        ->and($game->roof)->toBe('outdoors')
        ->and($game->surface)->toBe('grass')
        ->and($game->division_game)->toBeTrue();

    $snapshot = GameOddsSnapshot::query()->where('game_id', $game->id)->firstOrFail();

    expect($snapshot->source)->toBe('nflverse')
        ->and(data_get($snapshot->market_context, 'line_type'))->toBe('closing')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.outcomes.0.point'))->toBe(-2.5)
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.1.outcomes.0.point'))->toBe(44.5);

    $weather = GameWeather::query()->where('game_id', $game->id)->firstOrFail();

    expect($weather->provider)->toBe('nflverse')
        ->and((float) $weather->temperature_f)->toBe(72.0)
        ->and((float) $weather->wind_speed_mph)->toBe(8.0)
        ->and($weather->is_indoor)->toBeFalse();
});

it('enriches existing espn games instead of duplicating them', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Chiefs',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'DEN',
        'location' => 'Denver',
        'name' => 'Broncos',
    ]);

    Game::factory()->create([
        'espn_event_id' => '401671789',
        'espn_uid' => 's:20~l:28~e:401671789',
        'season' => 2026,
        'season_type' => '2',
        'week' => 1,
        'game_date' => '2026-09-13',
        'game_time' => '20:20:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => null,
        'away_score' => null,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $path = sys_get_temp_dir().'/nflverse-schedules-existing-test.csv';
    File::put($path, implode("\n", [
        'game_id,season,game_type,week,gameday,gametime,away_team,home_team,away_score,home_score,espn,spread_line,total_line,away_qb_id,away_qb_name,home_qb_id,home_qb_name,away_coach,home_coach,stadium,roof,surface',
        '2026_01_DEN_KC,2026,REG,1,2026-09-13,20:20,DEN,KC,,,401671789,-2.5,44.5,00-0031234,Bo Nix,00-0033873,Patrick Mahomes,Sean Payton,Andy Reid,GEHA Field,outdoors,grass',
    ]));

    artisan('nfl:import-nflverse-schedules', ['file' => $path])
        ->assertExitCode(0);

    expect(Game::query()->count())->toBe(1);

    $game = Game::query()->firstOrFail();

    expect($game->espn_event_id)->toBe('401671789')
        ->and($game->nflverse_game_id)->toBe('2026_01_DEN_KC')
        ->and($game->home_qb_name)->toBe('Patrick Mahomes')
        ->and($game->odds_data)->not->toBeNull();
});
