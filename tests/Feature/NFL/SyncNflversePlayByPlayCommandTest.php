<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('imports the public release and verifies both teams have completed-game plays', function () {
    $this->travelTo(now()->setDate(2026, 9, 30));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $home = Team::factory()->create(['abbreviation' => 'KC']);
    $away = Team::factory()->create(['abbreviation' => 'DEN']);
    Game::factory()->create(['season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-10', 'nflverse_game_id' => '2026_01_DEN_KC',
        'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $csv = "game_id,play_id,season,week,home_team,away_team,posteam,defteam,play_type,epa,yards_gained,yards_after_catch\n";
    foreach (range(1, 120) as $id) {
        $teams = $id <= 60 ? 'KC,DEN' : 'DEN,KC';
        $csv .= "2026_01_DEN_KC,{$id},2026,1,KC,DEN,{$teams},pass,0.1,4,-2\n";
    }
    Http::fake(['*' => Http::response(gzencode($csv))]);
    $this->artisan('nfl:sync-nflverse-pbp --season=2026')->expectsOutputToContain('Completed-game play coverage: 1/1')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->count())->toBe(120)
        ->and(DB::table('nflverse_pbp_plays')->where('yards_after_catch', -2)->count())->toBe(120);
    $this->artisan('nfl:sync-nflverse-pbp --season=2026')->assertSuccessful();
    expect(DB::table('nflverse_pbp_plays')->count())->toBe(120);
});

it('fails without importing invalid or wrong-season source data', function (string $csv) {
    $this->travelTo(now()->setDate(2026, 9, 30));
    Http::fake(['*' => Http::response(gzencode($csv))]);
    $this->artisan('nfl:sync-nflverse-pbp --season=2026')->assertFailed();
    expect(DB::table('nflverse_pbp_plays')->count())->toBe(0);
})->with([
    'html error' => '<html>Error</html>',
    'wrong season' => "game_id,play_id,season,week,home_team,away_team,posteam,defteam,play_type,epa,yards_gained\n2025_01_DEN_KC,1,2025,1,KC,DEN,KC,DEN,pass,0.1,4\n",
]);

it('reports a failed sync when the provider release omits a completed game', function () {
    $this->travelTo(now()->setDate(2026, 9, 30));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    Game::factory()->create(['season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-10', 'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $csv = "game_id,play_id,season,week,home_team,away_team,posteam,defteam,play_type,epa,yards_gained\n2026_01_DEN_KC,1,2026,1,KC,DEN,KC,DEN,pass,0.1,4\n";
    Http::fake(['*' => Http::response(gzencode($csv))]);
    $this->artisan('nfl:sync-nflverse-pbp --season=2026')
        ->expectsOutputToContain('Completed-game play coverage: 0/1')
        ->expectsOutputToContain('Missing or incomplete provider plays')->assertFailed();
});
