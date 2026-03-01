<?php

use App\Models\Healthcheck;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Healthcheck::query()->delete();
});

it('records validation checks with validation_ prefix', function () {
    artisan('healthcheck:validate-data --sport=cbb');

    $check = Healthcheck::query()
        ->where('sport', 'cbb')
        ->where('check_type', 'validation_game_coverage')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull();
});

it('can pass team stat coverage validation when teams have stats for season games', function () {
    $teamIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $teamIds[] = DB::table('cbb_teams')->insertGetId([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $season = (int) now()->year;

    $gameId = DB::table('cbb_games')->insertGetId([
        'espn_event_id' => 'validation-test-game-1',
        'home_team_id' => $teamIds[0],
        'away_team_id' => $teamIds[1],
        'season' => $season,
        'week' => 1,
        'game_date' => now(),
        'game_time' => '19:00:00',
        'status' => 'STATUS_FINAL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cbb_team_stats')->insert([
        [
            'team_id' => $teamIds[0],
            'game_id' => $gameId,
            'team_type' => 'home',
            'points' => 77,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'team_id' => $teamIds[1],
            'game_id' => $gameId,
            'team_type' => 'away',
            'points' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    artisan('healthcheck:validate-data --sport=cbb');

    $statsCheck = Healthcheck::query()
        ->where('sport', 'cbb')
        ->where('check_type', 'validation_team_stat_coverage')
        ->latest('id')
        ->first();

    expect($statsCheck)->not->toBeNull();
    expect($statsCheck->status)->toBe('failing');
    expect($statsCheck->message)->toContain('missing team stats');
});
