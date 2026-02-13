<?php

use App\Models\Healthcheck;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Clean up healthchecks table before each test
    Healthcheck::query()->delete();
});

it('records team metrics check when all teams have recent metrics', function () {
    // Create 5 teams
    for ($i = 1; $i <= 5; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create recent team metrics for all teams
    $teams = DB::table('cbb_teams')->get();
    foreach ($teams as $team) {
        DB::table('cbb_team_metrics')->insert([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 105.5,
            'defensive_efficiency' => 95.5,
            'tempo' => 70.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('passing')
        ->message->toContain('5/5 teams have metrics calculated');
});

it('records warning when some teams are missing metrics', function () {
    // Create 10 teams
    for ($i = 1; $i <= 10; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Only create metrics for 7 teams
    $teams = DB::table('cbb_teams')->limit(7)->get();
    foreach ($teams as $team) {
        DB::table('cbb_team_metrics')->insert([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 105.5,
            'defensive_efficiency' => 95.5,
            'tempo' => 70.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('warning')
        ->message->toContain('7/10 teams have metrics')
        ->message->toContain('3 teams missing');
});

it('records warning when metrics are stale', function () {
    // Create 10 teams
    for ($i = 1; $i <= 10; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create metrics for all teams but make them stale (older than 3 days)
    $teams = DB::table('cbb_teams')->get();
    foreach ($teams as $team) {
        DB::table('cbb_team_metrics')->insert([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 105.5,
            'defensive_efficiency' => 95.5,
            'tempo' => 70.0,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('warning')
        ->message->toContain('may be stale');
});

it('records failing when no team metrics exist', function () {
    // Create teams but no metrics
    for ($i = 1; $i <= 5; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('No team metrics found')
        ->message->toContain('cbb:calculate-team-metrics');
});

it('records failing when less than 50% of teams have metrics', function () {
    // Create 10 teams
    for ($i = 1; $i <= 10; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Only create metrics for 4 teams (less than 50%)
    $teams = DB::table('cbb_teams')->limit(4)->get();
    foreach ($teams as $team) {
        DB::table('cbb_team_metrics')->insert([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 105.5,
            'defensive_efficiency' => 95.5,
            'tempo' => 70.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('Only 4/10 teams have metrics calculated');
});

it('records failing when no teams exist in database', function () {
    // Run healthcheck with no teams
    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('No teams found in database');

    expect($check->metadata)->toEqual([
        'total_teams' => 0,
        'teams_with_metrics' => 0,
        'recently_updated' => 0,
    ]);
});

it('skips team metrics check for sports without team metrics support', function () {
    artisan('healthcheck:run --sport=nfl');

    // Should not create a team_metrics check for NFL
    $check = Healthcheck::where('sport', 'nfl')
        ->where('check_type', 'team_metrics')
        ->latest('id')
        ->first();

    expect($check)->toBeNull();
});

it('only checks team metrics for supported sports', function () {
    artisan('healthcheck:run');

    // Should have team_metrics checks for these sports
    $supportedSports = ['mlb', 'nba', 'cbb', 'wcbb', 'wnba'];

    foreach ($supportedSports as $sport) {
        $check = Healthcheck::where('sport', $sport)
            ->where('check_type', 'team_metrics')
            ->latest('id')
            ->first();

        expect($check)->not->toBeNull();
    }

    // Should NOT have team_metrics checks for these sports
    $unsupportedSports = ['nfl', 'cfb'];

    foreach ($unsupportedSports as $sport) {
        $check = Healthcheck::where('sport', $sport)
            ->where('check_type', 'team_metrics')
            ->latest('id')
            ->first();

        expect($check)->toBeNull();
    }
});

// Team Schedules Health Check Tests

it('records passing status when all teams have appropriate number of games', function () {
    // Create 5 teams
    $teamIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $teamIds[] = DB::table('cbb_teams')->insertGetId([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create exactly 30 games for each team - all teams have same count
    $season = now()->year;
    for ($i = 0; $i < 5; $i++) {
        for ($j = 0; $j < 30; $j++) {
            DB::table('cbb_games')->insert([
                'espn_event_id' => "game-{$i}-{$j}",
                'home_team_id' => $teamIds[$i],
                'away_team_id' => $teamIds[($i + 1) % 5],
                'season' => $season,
                'week' => 1,
                'game_date' => now()->addDays($j),
                'game_time' => '12:00:00',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('passing')
        ->message->toContain('Team schedules look good');

    expect($check->metadata)
        ->toHaveKey('total_teams', 5)
        ->toHaveKey('teams_with_games', 5)
        ->toHaveKey('teams_with_no_games', 0)
        ->toHaveKey('average_games')
        ->toHaveKey('outliers');
});

it('records warning status when some teams have no games', function () {
    // Create 20 teams
    $teamIds = [];
    for ($i = 1; $i <= 20; $i++) {
        $teamIds[] = DB::table('cbb_teams')->insertGetId([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create games for only 19 teams (1 team = 5% has no games - under 10% threshold)
    $season = now()->year;
    for ($i = 0; $i < 19; $i++) {
        for ($j = 0; $j < 15; $j++) {
            DB::table('cbb_games')->insert([
                'espn_event_id' => "game-{$i}-{$j}",
                'home_team_id' => $teamIds[$i],
                'away_team_id' => $teamIds[($i + 1) % 19],
                'season' => $season,
                'week' => 1,
                'game_date' => now()->addDays($j),
                'game_time' => '12:00:00',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('warning')
        ->message->toContain('Some schedule issues');

    expect($check->metadata)
        ->toHaveKey('teams_with_no_games', 1);

    expect($check->metadata['outliers'])->toHaveCount(1);
});

it('records failing status when more than 10% of teams have no games', function () {
    // Create 10 teams
    $teamIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $teamIds[] = DB::table('cbb_teams')->insertGetId([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create games for only 6 teams (4 teams = 40% have no games - exceeds 10% threshold)
    $season = now()->year;
    for ($i = 0; $i < 6; $i++) {
        for ($j = 0; $j < 15; $j++) {
            DB::table('cbb_games')->insert([
                'espn_event_id' => "game-{$i}-{$j}",
                'home_team_id' => $teamIds[$i],
                'away_team_id' => $teamIds[($i + 1) % 6],
                'season' => $season,
                'week' => 1,
                'game_date' => now()->addDays($j),
                'game_time' => '12:00:00',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('Significant schedule issues');

    expect($check->metadata)
        ->toHaveKey('teams_with_no_games', 4);
});

it('detects outliers with too many or too few games', function () {
    // Create 10 teams
    $teamIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $teamIds[] = DB::table('cbb_teams')->insertGetId([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $season = now()->year;

    // Most teams get 30 games (both home and away)
    for ($i = 0; $i < 8; $i++) {
        for ($j = 0; $j < 30; $j++) {
            $opponent = $teamIds[($i + 1) % 10];
            DB::table('cbb_games')->insert([
                'espn_event_id' => "{$teamIds[$i]}-{$opponent}-{$j}",
                'home_team_id' => $teamIds[$i],
                'away_team_id' => $opponent,
                'season' => $season,
                'week' => 1,
                'game_date' => now()->addDays($j),
                'game_time' => '12:00:00',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // One team gets only 5 games (outlier - too few)
    for ($j = 0; $j < 5; $j++) {
        DB::table('cbb_games')->insert([
            'espn_event_id' => "{$teamIds[8]}-{$teamIds[0]}-{$j}",
            'home_team_id' => $teamIds[8],
            'away_team_id' => $teamIds[0],
            'season' => $season,
            'week' => 1,
            'game_date' => now()->addDays($j),
            'game_time' => '12:00:00',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // One team gets 60 games (outlier - too many)
    for ($j = 0; $j < 60; $j++) {
        DB::table('cbb_games')->insert([
            'espn_event_id' => "{$teamIds[9]}-{$teamIds[0]}-{$j}",
            'home_team_id' => $teamIds[9],
            'away_team_id' => $teamIds[0],
            'season' => $season,
            'week' => 1,
            'game_date' => now()->addDays($j),
            'game_time' => '12:00:00',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull();

    // Should detect outliers when game counts vary significantly
    expect($check->metadata['outliers'])->not->toBeEmpty();
});

it('records failing status when no teams exist for team schedules', function () {
    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('No teams found in database');

    expect($check->metadata)->toEqual([
        'total_teams' => 0,
        'teams_with_no_games' => 0,
        'outliers' => [],
    ]);
});

it('records failing status when no games exist for current season', function () {
    // Create teams but no games
    for ($i = 1; $i <= 5; $i++) {
        DB::table('cbb_teams')->insert([
            'espn_id' => (string) $i,
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => "T{$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    artisan('healthcheck:run --sport=cbb');

    $check = Healthcheck::where('sport', 'cbb')
        ->where('check_type', 'team_schedules')
        ->latest('id')
        ->first();

    expect($check)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('No games found for season');

    expect($check->metadata)
        ->toHaveKey('total_teams', 5)
        ->toHaveKey('teams_with_games', 0)
        ->toHaveKey('teams_with_no_games', 5);
});
