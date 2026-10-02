<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function chartingPlay(): int
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);

    return DB::table('nflverse_pbp_plays')->insertGetId(['nflverse_play_key' => '2026_01_DEN_KC|1',
        'nfl_game_id' => $game->id, 'nflverse_game_id' => '2026_01_DEN_KC', 'play_id' => '1', 'season' => 2026,
        'play_type' => 'pass', 'epa' => .25]);
}

function chartingCsv(string $values = 'TRUE,FALSE,TRUE,,6,1,5', string $read = 'CHK'): string
{
    return "season,nflverse_game_id,nflverse_play_id,is_play_action,is_screen_pass,is_rpo,is_motion,n_defense_box,n_blitzers,n_pass_rushers,read_thrown\n2026,2026_01_DEN_KC,1,{$values},{$read}\n";
}

it('joins charting by exact game and play identity while preserving original play values and unknown flags', function () {
    $this->travelTo(now()->setDate(2026, 9, 30));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $id = chartingPlay();
    Http::fake(['*' => Http::response(chartingCsv())]);
    $this->artisan('nfl:sync-matchup-charting --season=2026')->expectsOutputToContain('Charted scrimmage coverage: 1/1')->assertSuccessful();
    $row = DB::table('nflverse_pbp_plays')->find($id);
    expect((int) $row->ftn_is_play_action)->toBe(1)->and((int) $row->ftn_is_screen_pass)->toBe(0)
        ->and($row->ftn_read_thrown)->toBe('CHK')->and($row->ftn_is_motion)->toBeNull()->and((float) $row->epa)->toBe(.25)
        ->and(DB::table('nflverse_pbp_plays')->count())->toBe(1);
});

it('rejects malformed charting without overwriting existing evidence', function (string $values) {
    $this->travelTo(now()->setDate(2026, 9, 30));
    $id = chartingPlay();
    Http::fake(['*' => Http::response(chartingCsv($values))]);
    $this->artisan('nfl:sync-matchup-charting --season=2026')->assertFailed();
    expect(DB::table('nflverse_pbp_plays')->find($id)->ftn_observed_at)->toBeNull();
})->with(['MAYBE,FALSE,TRUE,,6,1,5', 'TRUE,FALSE,TRUE,,16,1,5']);

it('preserves each provider read code and leaves uncoded reads unknown', function (string $code, ?string $expected) {
    $this->travelTo(now()->setDate(2026, 10, 1));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $id = chartingPlay();
    Http::fake(['*' => Http::response(chartingCsv(read: $code))]);
    $this->artisan('nfl:sync-matchup-charting --season=2026')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->find($id)->ftn_read_thrown)->toBe($expected);
})->with([['0', '0'], ['1', '1'], ['2', '2'], ['CHK', 'CHK'], ['DES', 'DES'], ['SD', 'SD'], ['NA', null], ['', null]]);

it('rejects an unknown read code before replacing any saved charting', function () {
    $this->travelTo(now()->setDate(2026, 10, 1));
    $id = chartingPlay();
    DB::table('nflverse_pbp_plays')->where('id', $id)->update(['ftn_read_thrown' => 'CHK']);
    $csv = chartingCsv(read: 'DES')."2026,2026_01_DEN_KC,2,TRUE,FALSE,TRUE,,6,1,5,UNKNOWN\n";
    Http::fake(['*' => Http::response($csv)]);
    $this->artisan('nfl:sync-matchup-charting --season=2026')->assertFailed();
    expect(DB::table('nflverse_pbp_plays')->find($id)->ftn_read_thrown)->toBe('CHK');
});
