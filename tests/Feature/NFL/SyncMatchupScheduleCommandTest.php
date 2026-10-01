<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('records source verified matchup history without changing the game and does not duplicate unchanged evidence', function () {
    $this->travelTo(now()->setDate(2026, 9, 30));
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $game = Game::factory()->create(['season' => 2026, 'season_type' => '2', 'week' => 1,
        'status' => 'STATUS_FINAL', 'espn_event_id' => '123', 'game_date' => '2026-09-13', 'game_time' => '17:00:00',
        'home_team_id' => Team::factory()->create(['abbreviation' => 'KC'])->id,
        'away_team_id' => Team::factory()->create(['abbreviation' => 'DEN'])->id,
        'home_score' => 24, 'away_score' => 21, 'home_rest' => null]);
    $before = $game->fresh()->getAttributes();
    $header = 'game_id,espn,season,game_type,week,gameday,gametime,home_team,away_team,home_score,away_score,spread_line,total_line,home_rest,away_rest,div_game,overtime';
    Http::fake(['*' => Http::sequence()
        ->push($header."\n2026_01_DEN_KC,123,2026,REG,1,2026-09-13,13:00,KC,DEN,24,21,3,44,7,7,1,0\n")
        ->push($header."\n2026_01_DEN_KC,123,2026,REG,1,2026-09-13,13:00,KC,DEN,24,21,3,44,7,7,1,0\n")
        ->push($header."\n2026_01_DEN_KC,123,2026,REG,1,2026-09-13,13:00,KC,DEN,27,21,3,44,7,7,1,0\n")]);
    $this->artisan('nfl:sync-matchup-schedule --season=2026')->expectsOutputToContain('1/1 games')->assertSuccessful();
    expect($game->fresh()->getAttributes())->toBe($before);
    $row = DB::table('nfl_matchup_schedule_evidence')->first();
    $evidence = json_decode($row->evidence, true);
    expect($evidence['home_handicap'])->toBe(-3)
        ->and($evidence['kickoff_at'])->toBe('2026-09-13T17:00:00+00:00')
        ->and($evidence['division_game'])->toBeTrue();
    $this->artisan('nfl:sync-matchup-schedule --season=2026')->assertSuccessful();
    expect(DB::table('nfl_matchup_schedule_evidence')->count())->toBe(1);
    $this->artisan('nfl:sync-matchup-schedule --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_schedule_evidence')->count())->toBe(1);
});
