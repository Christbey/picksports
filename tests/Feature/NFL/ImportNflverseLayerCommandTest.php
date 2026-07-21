<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

uses()->group('nfl');

it('imports nflverse play by play rows and links them to nfl games', function () {
    $home = Team::factory()->create(['abbreviation' => 'KC']);
    $away = Team::factory()->create(['abbreviation' => 'DEN']);

    $game = Game::factory()->create([
        'nflverse_game_id' => '2025_01_DEN_KC',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2025,
        'week' => 1,
    ]);

    $path = sys_get_temp_dir().'/nflverse-pbp-test.csv';
    File::put($path, implode("\n", [
        'game_id,play_id,season,week,season_type,home_team,away_team,posteam,defteam,qtr,down,ydstogo,yardline_100,yards_gained,game_seconds_remaining,play_type,desc,epa,wp,wpa,passer_player_id,passer_player_name,rusher_player_id,rusher_player_name,receiver_player_id,receiver_player_name,touchdown,interception,fumble_lost,sack',
        '2025_01_DEN_KC,101,2025,1,REG,KC,DEN,DEN,KC,1,1,10,75,8,3550,pass,"Bo Nix pass short right to Courtland Sutton for 8 yards",0.42,0.47,0.02,00-0039918,Bo Nix,,,00-0034348,Courtland Sutton,0,0,0,0',
    ]));

    artisan('nfl:import-nflverse-layer', [
        'dataset' => 'pbp',
        'file' => $path,
    ])->assertExitCode(0);

    $row = DB::table('nflverse_pbp_plays')->first();

    expect((int) $row->nfl_game_id)->toBe($game->id)
        ->and($row->nflverse_game_id)->toBe('2025_01_DEN_KC')
        ->and($row->possession_team)->toBe('DEN')
        ->and($row->defense_team)->toBe('KC')
        ->and($row->play_type)->toBe('pass')
        ->and((float) $row->epa)->toBe(0.42);
});

it('imports nflverse roster rows with team and player identifiers', function () {
    Team::factory()->create(['abbreviation' => 'JAX']);

    $path = sys_get_temp_dir().'/nflverse-rosters-test.csv';
    File::put($path, implode("\n", [
        'season,team,gsis_id,espn_id,pfr_id,full_name,first_name,last_name,position,jersey_number,status,years_exp,birth_date,height,weight',
        '2025,JAC,00-0036971,4360310,LawrTr00,Trevor Lawrence,Trevor,Lawrence,QB,16,ACT,4,1999-10-06,78,220',
    ]));

    artisan('nfl:import-nflverse-layer', [
        'dataset' => 'rosters',
        'file' => $path,
    ])->assertExitCode(0);

    $row = DB::table('nflverse_rosters')->first();

    expect($row->team)->toBe('JAX')
        ->and($row->gsis_id)->toBe('00-0036971')
        ->and($row->espn_id)->toBe('4360310')
        ->and($row->full_name)->toBe('Trevor Lawrence')
        ->and($row->position)->toBe('QB');
});

it('imports nflverse depth chart rows', function () {
    Team::factory()->create(['abbreviation' => 'CHI']);

    $path = sys_get_temp_dir().'/nflverse-depth-test.csv';
    File::put($path, implode("\n", [
        'season,week,game_type,club_code,gsis_id,full_name,position,depth_team,depth_position,formation,depth_rank',
        '2025,1,REG,CHI,00-0039912,Caleb Williams,QB,Offense,QB,Base,1',
    ]));

    artisan('nfl:import-nflverse-layer', [
        'dataset' => 'depth-charts',
        'file' => $path,
    ])->assertExitCode(0);

    $row = DB::table('nflverse_depth_charts')->first();

    expect($row->team)->toBe('CHI')
        ->and($row->season_type)->toBe('REG')
        ->and($row->depth_position)->toBe('QB')
        ->and((int) $row->depth_rank)->toBe(1);
});

it('imports nflverse injury report rows', function () {
    Team::factory()->create(['abbreviation' => 'CIN']);

    $path = sys_get_temp_dir().'/nflverse-injuries-test.csv';
    File::put($path, implode("\n", [
        'season,week,game_type,team,gsis_id,full_name,position,report_primary_injury,report_status,practice_status,date_modified',
        '2025,2,REG,CIN,00-0036442,Joe Burrow,QB,Wrist,Questionable,Limited Participation,2025-09-12 18:30:00',
    ]));

    artisan('nfl:import-nflverse-layer', [
        'dataset' => 'injuries',
        'file' => $path,
    ])->assertExitCode(0);

    $row = DB::table('nflverse_injuries')->first();

    expect($row->team)->toBe('CIN')
        ->and($row->full_name)->toBe('Joe Burrow')
        ->and($row->report_primary_injury)->toBe('Wrist')
        ->and($row->report_status)->toBe('Questionable')
        ->and($row->practice_status)->toBe('Limited Participation');
});

it('imports nflverse weekly player stats rows', function () {
    $home = Team::factory()->create(['abbreviation' => 'BAL']);
    $away = Team::factory()->create(['abbreviation' => 'IND']);

    $game = Game::factory()->create([
        'nflverse_game_id' => '2025_01_BAL_IND',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2025,
        'week' => 1,
    ]);

    $path = sys_get_temp_dir().'/nflverse-weekly-stats-test.csv';
    File::put($path, implode("\n", [
        'player_id,player_name,player_display_name,position,position_group,season,week,season_type,game_id,team,opponent_team,attempts,passing_yards,passing_tds,passing_interceptions,carries,rushing_yards,rushing_tds,targets,receptions,receiving_yards,receiving_tds,fantasy_points_ppr',
        '00-0034796,L.Jackson,Lamar Jackson,QB,QB,2025,1,REG,2025_01_BAL_IND,BAL,IND,28,265,2,0,9,64,1,0,0,0,0,31.0',
    ]));

    artisan('nfl:import-nflverse-layer', [
        'dataset' => 'weekly-stats',
        'file' => $path,
    ])->assertExitCode(0);

    $row = DB::table('nflverse_weekly_player_stats')->first();

    expect((int) $row->nfl_game_id)->toBe($game->id)
        ->and($row->team)->toBe('BAL')
        ->and($row->opponent_team)->toBe('IND')
        ->and($row->player_display_name)->toBe('Lamar Jackson')
        ->and((int) $row->passing_yards)->toBe(265)
        ->and((float) $row->fantasy_points_ppr)->toBe(31.0);
});
