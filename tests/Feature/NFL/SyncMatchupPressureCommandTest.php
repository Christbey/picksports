<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function pressureCsv(string $counts = '5,1,0.25', string $opponent = 'KC'): string
{
    return "game_id,season,week,game_type,team,opponent,pfr_player_id,times_pressured,times_sacked,times_pressured_pct\n2026_01_DEN_KC,2026,1,REG,DEN,{$opponent},TestQB00,{$counts}\n";
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    DB::table('nflverse_pbp_plays')->insert(['nflverse_play_key' => 'pressure-test', 'nflverse_game_id' => '2026_01_DEN_KC',
        'nfl_game_id' => $game->id, 'season' => 2026, 'possession_team' => 'DEN', 'defense_team' => 'KC', 'possession_team_id' => $away->id, 'defense_team_id' => $home->id]);
    DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'pressure-roster', 'season' => 2026, 'team_id' => $away->id, 'pfr_id' => 'TestQB00', 'gsis_id' => '00-12345']);
});

it('imports mapped pressure counts and retains unknown values as null', function (string $counts, ?int $pressure) {
    Http::fake(['*' => Http::response(pressureCsv($counts))]);
    $this->artisan('nfl:sync-matchup-pressure --season=2026')->assertSuccessful();
    $row = DB::table('nfl_matchup_pressure_samples')->first();
    expect($row->gsis_id)->toBe('00-12345')->and($row->pressures === null ? null : (int) $row->pressures)->toBe($pressure);
})->with([['5,1,0.25', 5], [',,', null]]);

it('rejects corrupt pressure input before publishing any sample', function (string $csv) {
    Http::fake(['*' => Http::response($csv)]);
    $this->artisan('nfl:sync-matchup-pressure --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_pressure_samples')->count())->toBe(0);
})->with([
    fn () => pressureCsv('1,2,.2'), fn () => pressureCsv('5,1,25'), fn () => pressureCsv('-1,0,0'),
    fn () => pressureCsv(opponent: 'SEA'), fn () => pressureCsv()."2026_01_DEN_KC,2026,1,REG,DEN,KC,TestQB00,5,1,.25\n",
]);

it('does not guess player identities from names or accept ambiguous roster IDs', function () {
    DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'conflict', 'season' => 2026,
        'team_id' => DB::table('nflverse_rosters')->value('team_id'), 'pfr_id' => 'TestQB00', 'gsis_id' => '00-99999']);
    Http::fake(['*' => Http::response(pressureCsv())]);
    $this->artisan('nfl:sync-matchup-pressure --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_pressure_samples')->count())->toBe(0);
});
