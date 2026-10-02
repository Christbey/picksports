<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function participationCsv(string $zone = 'MAN_COVERAGE', string $coverage = 'COVER_1', string $team = 'DEN'): string
{
    return "nflverse_game_id,play_id,possession_team,defense_man_zone_type,defense_coverage_type\n2025_01_DEN_KC,1,{$team},{$zone},{$coverage}\n";
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
});

function participationPlay(): int
{
    return DB::table('nflverse_pbp_plays')->insertGetId(['nflverse_play_key' => '2025_01_DEN_KC|1',
        'nfl_game_id' => Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id])->id, 'nflverse_game_id' => '2025_01_DEN_KC', 'play_id' => '1',
        'season' => 2025, 'possession_team' => 'DEN', 'play_type' => 'pass', 'epa' => .25, 'ftn_read_thrown' => 'CHK']);
}

it('imports exact coverage identities and preserves independent charting and EPA', function () {
    $id = participationPlay();
    Http::fake(['*' => Http::sequence()->push(participationCsv())->push(participationCsv('', 'NA'))]);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    $row = DB::table('nflverse_pbp_plays')->find($id);
    expect($row->participation_man_zone)->toBe('MAN_COVERAGE')->and($row->participation_coverage)->toBe('COVER_1')
        ->and($row->ftn_read_thrown)->toBe('CHK')->and((float) $row->epa)->toBe(.25);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->find($id)->participation_man_zone)->toBeNull();
});

it('rejects corrupt participation atomically', function (string $csv) {
    $id = participationPlay();
    DB::table('nflverse_pbp_plays')->where('id', $id)->update(['participation_coverage' => 'COVER_3']);
    Http::fake(['*' => Http::response($csv)]);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertFailed();
    expect(DB::table('nflverse_pbp_plays')->find($id)->participation_coverage)->toBe('COVER_3');
})->with([
    'unknown code' => fn () => participationCsv('GUESS'),
    'team mismatch' => fn () => participationCsv(team: 'KC'),
    'duplicate' => fn () => participationCsv()."2025_01_DEN_KC,1,DEN,MAN_COVERAGE,COVER_1\n",
    'wrong season after valid row' => fn () => participationCsv()."2024_01_DEN_KC,2,DEN,MAN_COVERAGE,COVER_1\n",
    'malformed after valid row' => fn () => participationCsv()."bad,row\n",
]);

it('does not request the unavailable current season or replace data after an HTTP failure', function () {
    $id = participationPlay();
    Http::fake(['*' => Http::response('', 404)]);
    $this->artisan('nfl:sync-matchup-participation --season=2026')->assertFailed();
    Http::assertNothingSent();
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertFailed();
    expect(DB::table('nflverse_pbp_plays')->find($id)->participation_observed_at)->toBeNull();
});

it('normalizes the existing play importer team aliases without accepting a different team', function (string $local, string $source) {
    $id = participationPlay();
    DB::table('nflverse_pbp_plays')->where('id', $id)->update(['possession_team' => $local]);
    Http::fake(['*' => Http::response(participationCsv(team: $source))]);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->find($id)->participation_coverage)->toBe('COVER_1');
})->with([['WSH', 'WAS'], ['LAR', 'LA']]);
