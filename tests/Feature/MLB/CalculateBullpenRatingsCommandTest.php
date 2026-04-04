<?php

use App\Models\MLB\BullpenRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;

uses()->group('mlb');

it('calculates and ranks mlb bullpen snapshots for a requested date', function () {
    $homeTeam = Team::factory()->create(['abbreviation' => 'HOM']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'AWY']);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-04-01',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $homeTeam->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'pitchers_used' => 5,
        'innings_pitched' => 9,
        'earned_runs' => 1,
        'hits_allowed' => 4,
        'walks_allowed' => 1,
        'strikeouts_pitched' => 11,
        'home_runs_allowed' => 0,
        'total_pitches' => 128,
    ]);

    TeamStat::factory()->create([
        'team_id' => $awayTeam->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'pitchers_used' => 6,
        'innings_pitched' => 9,
        'earned_runs' => 5,
        'hits_allowed' => 10,
        'walks_allowed' => 5,
        'strikeouts_pitched' => 6,
        'home_runs_allowed' => 2,
        'total_pitches' => 166,
    ]);

    $this->artisan('mlb:calculate-bullpen-ratings', [
        '--season' => 2026,
        '--season-type' => (string) config('mlb.season.types.regular'),
        '--date' => '2026-04-02',
    ])->assertSuccessful();

    $homeRating = BullpenRating::query()
        ->where('team_id', $homeTeam->id)
        ->whereDate('as_of_date', '2026-04-02')
        ->first();
    $awayRating = BullpenRating::query()
        ->where('team_id', $awayTeam->id)
        ->whereDate('as_of_date', '2026-04-02')
        ->first();

    expect($homeRating)->not->toBeNull()
        ->and($awayRating)->not->toBeNull()
        ->and((float) $homeRating->rating_score)->toBeGreaterThan((float) $awayRating->rating_score)
        ->and($homeRating->rating_rank)->toBe(1)
        ->and($awayRating->rating_rank)->toBe(2);
});
