<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function participationCsv(string $zone = 'MAN_COVERAGE', string $coverage = 'COVER_1', string $team = 'DEN',
    string $pressure = 'TRUE', string $rushers = '4', string $offense = '1 QB, 1 RB, 2 TE, 2 WR, 1 C, 2 G, 2 T',
    string $defense = '3 CB, 1 FS, 1 SS, 2 DE, 2 DT, 2 LB'): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, ['nflverse_game_id', 'play_id', 'possession_team', 'defense_man_zone_type', 'defense_coverage_type',
        'was_pressure', 'number_of_pass_rushers', 'offense_personnel', 'defense_personnel'], escape: '');
    fputcsv($stream, ['2025_01_DEN_KC', '1', $team, $zone, $coverage, $pressure, $rushers, $offense, $defense], escape: '');
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
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
        ->and((bool) $row->participation_pressure)->toBeTrue()->and((int) $row->participation_rushers)->toBe(4)
        ->and($row->participation_offense_package)->toBe('12')->and((int) $row->participation_defense_dbs)->toBe(5)
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
    'unknown pressure' => fn () => participationCsv(pressure: 'probably'),
    'invalid rushers' => fn () => participationCsv(rushers: '12'),
    'duplicate position' => fn () => participationCsv(offense: '1 QB, 1 RB, 1 RB, 2 TE, 1 WR, 5 T'),
    'unknown position' => fn () => participationCsv(defense: '11 UNKNOWN'),
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

it('preserves unknown charting and distinguishes fullbacks nonstandard groups and incomplete personnel', function () {
    $id = participationPlay();
    Http::fake(['*' => Http::sequence()
        ->push(participationCsv(pressure: '', rushers: 'NA', offense: '1 QB, 1 RB, 1 FB, 1 TE, 2 WR, 5 T', defense: '2 CB, 2 S, 7 LB'))
        ->push(participationCsv(offense: '1 QB, 1 RB, 1 TE, 2 WR, 5 T', defense: '2 CB, 2 S, 6 LB'))
        ->push(participationCsv(offense: '1 QB, 1 RB, 1 TE, 2 WR, 6 T'))]);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    $row = DB::table('nflverse_pbp_plays')->find($id);
    expect($row->participation_pressure)->toBeNull()->and($row->participation_rushers)->toBeNull()
        ->and($row->participation_offense_package)->toBe('21')->and((int) $row->participation_defense_dbs)->toBe(4);
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    $row = DB::table('nflverse_pbp_plays')->find($id);
    expect($row->participation_offense_package)->toBeNull()->and($row->participation_defense_dbs)->toBeNull();
    $this->artisan('nfl:sync-matchup-participation --season=2025')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->find($id)->participation_offense_package)->toBe('OTHER');
});
