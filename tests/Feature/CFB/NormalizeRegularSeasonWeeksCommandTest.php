<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;

uses()->group('cfb', 'commands');

it('normalizes stored regular season cfb weeks from game dates', function () {
    [$homeTeam, $awayTeam] = [
        Team::factory()->create(['division' => config('cfb.teams.divisions.fbs', 'FBS')]),
        Team::factory()->create(['division' => config('cfb.teams.divisions.fbs', 'FBS')]),
    ];

    $weekZeroGame = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-08-29',
        'game_time' => '16:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $weekOneGame = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2026-09-03',
        'game_time' => '23:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $this->artisan('cfb:normalize-regular-season-weeks', [
        '--season' => 2026,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($weekZeroGame->fresh()->week)->toBe(1);

    $this->artisan('cfb:normalize-regular-season-weeks', [
        '--season' => 2026,
    ])
        ->expectsOutputToContain('Normalized 1 CFB regular-season game week value for season 2026.')
        ->assertSuccessful();

    expect($weekZeroGame->fresh()->week)->toBe(0)
        ->and($weekOneGame->fresh()->week)->toBe(1);
});
