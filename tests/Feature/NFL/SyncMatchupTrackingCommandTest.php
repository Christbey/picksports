<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function trackingPassingCsv(string $rows = "2026,REG,1,DEN,00-0001031,QB,2.5,20\n"): string
{
    return "season,season_type,week,team_abbr,player_gsis_id,player_position,avg_time_to_throw,attempts\n".$rows;
}

function trackingDefenseCsv(string $rows = "2026,REG,2026_01_DEN_KC,DEN,KC,Defender01,5,1\n"): string
{
    return "season,game_type,game_id,team,opponent,pfr_player_id,def_tackles_combined,def_missed_tackles\n".$rows;
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    DB::table('nflverse_pbp_plays')->insert(['nflverse_play_key' => 'tracking-test', 'nflverse_game_id' => '2026_01_DEN_KC',
        'nfl_game_id' => $game->id, 'season' => 2026, 'week' => 1, 'possession_team' => 'DEN', 'defense_team' => 'KC', 'possession_team_id' => $away->id]);
});

it('imports weekly tracking and tackle opportunities without counting season totals', function () {
    Http::fake([
        '*ngs_passing.csv.gz' => Http::response(gzencode(trackingPassingCsv("2026,REG,0,DEN,00-0001031,QB,9,80\n2026,REG,1,DEN,00-0001031,QB,2.5,20\n"))),
        '*advstats_week_def_2026.csv' => Http::response(trackingDefenseCsv()),
    ]);
    $this->artisan('nfl:sync-matchup-tracking --season=2026')->assertSuccessful();
    $qb = DB::table('nfl_matchup_tracking_samples')->where('metric', 'release_time')->first();
    $tackles = DB::table('nfl_matchup_tracking_samples')->where('metric', 'missed_tackles')->first();
    expect(DB::table('nfl_matchup_tracking_samples')->count())->toBe(2)
        ->and($qb->player_id)->toBe('gsis:00-0001031')->and((float) $qb->value)->toBe(2.5)
        ->and((int) $tackles->sample_size)->toBe(6)->and((float) $tackles->value)->toBe(1.0);
});

it('publishes neither source when the defensive source is corrupt', function (string $defense) {
    Http::fake(['*ngs_passing.csv.gz' => Http::response(gzencode(trackingPassingCsv())), '*advstats_week_def_2026.csv' => Http::response($defense)]);
    $this->artisan('nfl:sync-matchup-tracking --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_tracking_samples')->count())->toBe(0);
})->with([
    fn () => trackingDefenseCsv("2026,REG,2026_01_DEN_KC,DEN,KC,Defender01,5,-1\n"),
    fn () => trackingDefenseCsv("2026,REG,2026_01_DEN_KC,DEN,SEA,Defender01,5,1\n"),
    fn () => trackingDefenseCsv()."2026,REG,2026_01_DEN_KC,DEN,KC,Defender01,5,1\n",
    fn () => trackingDefenseCsv()."broken,row\n",
]);

it('rejects invalid QB values or duplicate weekly identities', function (string $passing) {
    Http::fake(['*ngs_passing.csv.gz' => Http::response(gzencode($passing)), '*advstats_week_def_2026.csv' => Http::response(trackingDefenseCsv())]);
    $this->artisan('nfl:sync-matchup-tracking --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_tracking_samples')->count())->toBe(0);
})->with([
    fn () => trackingPassingCsv("2026,REG,1,DEN,00-0001031,QB,99,20\n"),
    fn () => trackingPassingCsv()."2026,REG,1,DEN,00-0001031,QB,2.5,20\n",
    fn () => trackingPassingCsv("2026,REG,1,DEN,unknown,QB,2.5,20\n"),
]);

it('preserves unknown tackle measurements rather than converting them to zero', function () {
    Http::fake(['*ngs_passing.csv.gz' => Http::response(gzencode(trackingPassingCsv())),
        '*advstats_week_def_2026.csv' => Http::response(trackingDefenseCsv("2026,REG,2026_01_DEN_KC,DEN,KC,Defender01,5,\n"))]);
    $this->artisan('nfl:sync-matchup-tracking --season=2026')->assertSuccessful();
    $row = DB::table('nfl_matchup_tracking_samples')->where('metric', 'missed_tackles')->first();
    expect($row->value)->toBeNull()->and($row->sample_size)->toBeNull();
});
