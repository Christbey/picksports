<?php

use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('requires complete unambiguous roster identities before any import', function (string $fault) {
    $this->travelTo('2026-09-30');
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $teams = Team::factory()->count(32)->create();
    $csv = "season,team,position,espn_id,gsis_id,full_name\n";
    foreach ($teams as $i => $team) {
        if ($fault === 'partial' && $i === 31) {
            continue;
        }
        $season = $fault === 'season' && $i === 31 ? 2025 : 2026;
        $csv .= "{$season},{$team->abbreviation},QB,".(1000 + $i).',00-'.(1000 + $i).",Test QB {$i}\n";
    }
    if ($fault === 'conflict') {
        $csv .= '2026,'.$teams[0]->abbreviation.",QB,1000,00-9999,Conflicting QB\n";
    }
    Http::fake(['*' => Http::response($csv)]);
    if ($fault === 'none') {
        $this->artisan('nfl:sync-matchup-rosters --season=2026')->assertSuccessful();
        expect(DB::table('nflverse_rosters')->whereNotNull('team_id')->count())->toBe(32);
    } else {
        $this->artisan('nfl:sync-matchup-rosters --season=2026')->assertFailed();
        expect(DB::table('nflverse_rosters')->count())->toBe(0);
    }
})->with(['none', 'partial', 'season', 'conflict']);
